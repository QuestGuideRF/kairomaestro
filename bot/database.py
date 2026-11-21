from __future__ import annotations
import hashlib
import json
import logging
from datetime import datetime, timezone
from typing import Any
import aiomysql
from formatting import identifier_fingerprint
from settings import Settings
LOGGER = logging.getLogger("azbuka_bot.database")
class Database:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings
        self.pool: aiomysql.Pool | None = None
    async def connect(self) -> None:
        self.pool = await aiomysql.create_pool(
            host=self.settings.db_host,
            port=self.settings.db_port,
            user=self.settings.db_user,
            password=self.settings.db_password,
            db=self.settings.db_name,
            charset="utf8mb4",
            minsize=1,
            maxsize=5,
            autocommit=True,
            connect_timeout=10,
            pool_recycle=300,
        )
        await self.ping()
    async def close(self) -> None:
        if self.pool:
            self.pool.close()
            await self.pool.wait_closed()
            self.pool = None
    async def ping(self) -> None:
        async with (
            self._pool().acquire() as connection,
            connection.cursor() as cursor,
        ):
            await cursor.execute("SELECT 1")
            await cursor.fetchone()
    def _pool(self) -> aiomysql.Pool:
        if not self.pool:
            raise RuntimeError("Database pool is not connected")
        return self.pool
    async def fetch_one(self, query: str, params: tuple[Any, ...] = ()) -> dict[str, Any] | None:
        async with (
            self._pool().acquire() as connection,
            connection.cursor(aiomysql.DictCursor) as cursor,
        ):
            await cursor.execute(query, params)
            return await cursor.fetchone()
    async def fetch_all(self, query: str, params: tuple[Any, ...] = ()) -> list[dict[str, Any]]:
        async with (
            self._pool().acquire() as connection,
            connection.cursor(aiomysql.DictCursor) as cursor,
        ):
            await cursor.execute(query, params)
            return list(await cursor.fetchall())
    async def execute(self, query: str, params: tuple[Any, ...] = ()) -> int:
        async with (
            self._pool().acquire() as connection,
            connection.cursor() as cursor,
        ):
            await cursor.execute(query, params)
            return cursor.rowcount
    async def claim_update(
        self,
        update_id: int,
        update_type: str,
        telegram_chat_id: int | None,
    ) -> bool:
        inserted = await self.execute(
            "INSERT IGNORE INTO telegram_updates "
            "(update_id,update_type,telegram_chat_id) VALUES (%s,%s,%s)",
            (update_id, update_type[:40], telegram_chat_id),
        )
        return inserted > 0
    async def enqueue_inbound_event(
        self,
        idempotency_key: str,
        event_type: str,
        payload: dict[str, Any],
    ) -> bool:
        inserted = await self.execute(
            "INSERT IGNORE INTO telegram_inbound_events "
            "(idempotency_key,event_type,telegram_chat_id,telegram_message_id,"
            "telegram_user_id,payload_json,status,next_attempt_at) "
            "VALUES (%s,%s,%s,%s,%s,%s,'pending',NOW())",
            (
                idempotency_key[:190],
                event_type[:24],
                int(payload.get("telegram_chat_id") or 0),
                int(payload.get("telegram_message_id") or 0) or None,
                int(payload.get("telegram_user_id") or 0),
                json.dumps(payload, ensure_ascii=False),
            ),
        )
        return inserted > 0
    async def claim_inbound_event(self, worker_id: str) -> dict[str, Any] | None:
        async with self._pool().acquire() as connection:
            await connection.begin()
            try:
                async with connection.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute(
                        "SELECT * FROM telegram_inbound_events "
                        "WHERE status IN ('pending','retry') "
                        "AND next_attempt_at<=NOW() AND attempts<max_attempts "
                        "ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED"
                    )
                    event = await cursor.fetchone()
                    if not event:
                        await connection.commit()
                        return None
                    await cursor.execute(
                        "UPDATE telegram_inbound_events "
                        "SET status='processing',locked_at=NOW(),locked_by=%s "
                        "WHERE id=%s",
                        (worker_id[:190], event["id"]),
                    )
                await connection.commit()
            except Exception:
                await connection.rollback()
                raise
        payload = event.get("payload_json")
        if isinstance(payload, str):
            event["payload_json"] = json.loads(payload)
        return event
    async def inbound_event_completed(
        self,
        event_id: int,
        site_message_id: int | None,
    ) -> None:
        await self.execute(
            "UPDATE telegram_inbound_events "
            "SET status='completed',attempts=attempts+1,site_message_id=%s,"
            "completed_at=NOW(),locked_at=NULL,locked_by=NULL,last_error=NULL,"
            "payload_json=JSON_OBJECT('redacted',TRUE) WHERE id=%s",
            (site_message_id, event_id),
        )
    async def inbound_event_failed(
        self,
        event: dict[str, Any],
        error: str,
        permanent: bool = False,
        site_message_id: int | None = None,
    ) -> bool:
        attempts = int(event.get("attempts") or 0) + 1
        max_attempts = max(1, int(event.get("max_attempts") or 20))
        if permanent or attempts >= max_attempts:
            updated = await self.execute(
                "UPDATE telegram_inbound_events "
                "SET status='failed',attempts=%s,site_message_id=COALESCE(%s,site_message_id),"
                "locked_at=NULL,locked_by=NULL,last_error=%s "
                "WHERE id=%s AND status='processing'",
                (attempts, site_message_id, error[:2000], event["id"]),
            )
            return updated > 0
        delay = min(900, max(2, 2 ** min(attempts, 8)))
        updated = await self.execute(
            "UPDATE telegram_inbound_events "
            "SET status='retry',attempts=%s,next_attempt_at=DATE_ADD(NOW(),INTERVAL %s SECOND),"
            "site_message_id=COALESCE(%s,site_message_id),locked_at=NULL,locked_by=NULL,"
            "last_error=%s WHERE id=%s AND status='processing'",
            (attempts, delay, site_message_id, error[:2000], event["id"]),
        )
        return updated > 0
    async def recover_stale_inbound_events(self) -> int:
        return await self.execute(
            "UPDATE telegram_inbound_events "
            "SET status='retry',locked_at=NULL,locked_by=NULL,"
            "last_error='Recovered stale inbound lock' "
            "WHERE status='processing' AND locked_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)"
        )
    async def flow_session(
        self,
        telegram_user_id: int,
        telegram_chat_id: int,
    ) -> dict[str, Any] | None:
        row = await self.fetch_one(
            "SELECT * FROM telegram_bot_sessions "
            "WHERE telegram_user_id=%s AND telegram_chat_id=%s AND expires_at>NOW() LIMIT 1",
            (telegram_user_id, telegram_chat_id),
        )
        if row and isinstance(row.get("payload_json"), str):
            row["payload_json"] = json.loads(row["payload_json"])
        return row
    async def save_flow(
        self,
        telegram_user_id: int,
        telegram_chat_id: int,
        flow_type: str,
        state: str,
        payload: dict[str, Any],
    ) -> None:
        await self.execute(
            "INSERT INTO telegram_bot_sessions "
            "(telegram_user_id,telegram_chat_id,flow_type,state,payload_json,expires_at) "
            "VALUES (%s,%s,%s,%s,%s,DATE_ADD(NOW(),INTERVAL %s HOUR)) AS new "
            "ON DUPLICATE KEY UPDATE flow_type=new.flow_type,state=new.state,"
            "payload_json=new.payload_json,expires_at=new.expires_at,updated_at=NOW()",
            (
                telegram_user_id,
                telegram_chat_id,
                flow_type[:32],
                state[:80],
                json.dumps(payload, ensure_ascii=False),
                self.settings.flow_ttl_hours,
            ),
        )
    async def delete_flow(self, telegram_user_id: int, telegram_chat_id: int) -> None:
        await self.execute(
            "DELETE FROM telegram_bot_sessions WHERE telegram_user_id=%s AND telegram_chat_id=%s",
            (telegram_user_id, telegram_chat_id),
        )
    async def linked_user(self, telegram_user_id: int) -> dict[str, Any] | None:
        return await self.fetch_one(
            "SELECT id,first_name,last_name,role,is_active,telegram_username,"
            "telegram_chat_id,telegram_status FROM users "
            "WHERE telegram_user_id=%s AND is_active=1 LIMIT 1",
            (telegram_user_id,),
        )
    async def link_user(self, token: str, telegram_user_id: int, chat_id: int, username: str | None) -> tuple[bool, str]:
        token_hash = hashlib.sha256(token.encode("utf-8")).hexdigest()
        async with self._pool().acquire() as connection:
            await connection.begin()
            try:
                async with connection.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute(
                        "SELECT t.id,t.user_id FROM telegram_link_tokens t JOIN users u ON u.id=t.user_id AND u.is_active=1 WHERE t.token_hash=%s AND t.used_at IS NULL AND t.expires_at>NOW() LIMIT 1 FOR UPDATE",
                        (token_hash,),
                    )
                    link = await cursor.fetchone()
                    if not link:
                        await connection.rollback()
                        return False, "Ссылка недействительна или уже использована. Создайте новую в личном кабинете."
                    await cursor.execute("SELECT id FROM users WHERE telegram_user_id=%s AND id<>%s LIMIT 1", (telegram_user_id, link["user_id"]))
                    if await cursor.fetchone():
                        await connection.rollback()
                        return False, "Этот Telegram уже привязан к другому аккаунту."
                    await cursor.execute(
                        "UPDATE users SET telegram_user_id=%s,telegram_chat_id=%s,"
                        "telegram_username=%s,telegram_linked_at=NOW(),telegram_status='active' "
                        "WHERE id=%s",
                        (telegram_user_id, chat_id, username, link["user_id"]),
                    )
                    await cursor.execute("UPDATE telegram_link_tokens SET used_at=NOW() WHERE id=%s", (link["id"],))
                    await cursor.execute(
                        "INSERT INTO audit_log (user_id,role,action,entity_type,entity_id,result,metadata) SELECT id,role,'telegram.linked','user',id,'success',%s FROM users WHERE id=%s",
                        (
                            json.dumps(
                                {
                                    "telegram_identity": identifier_fingerprint(
                                        telegram_user_id
                                    )
                                },
                                ensure_ascii=False,
                            ),
                            link["user_id"],
                        ),
                    )
                await connection.commit()
                try:
                    await self.resume_invites_for_user(int(link["user_id"]))
                except Exception:
                    LOGGER.exception(
                        "Telegram linked, but pending invites could not be resumed "
                        "for user %s",
                        link["user_id"],
                    )
                return True, "Telegram успешно привязан. Уведомления включены."
            except Exception:
                await connection.rollback()
                raise
    async def auth_request_preview(
        self,
        token: str,
        purpose: str,
        telegram_user_id: int,
    ) -> tuple[bool, str, dict[str, Any] | None]:
        token_hash = hashlib.sha256(token.encode("utf-8")).hexdigest()
        async with self._pool().acquire() as connection:
            async with connection.cursor(aiomysql.DictCursor) as cursor:
                await cursor.execute(
                    "SELECT id,request_ip,created_at,user_id,approved_at "
                    "FROM telegram_auth_requests "
                    "WHERE token_hash=%s AND purpose=%s AND consumed_at IS NULL "
                    "AND expires_at>NOW() LIMIT 1",
                    (token_hash, purpose),
                )
                request = await cursor.fetchone()
                if not request:
                    return False, (
                        "Ссылка недействительна или уже использована. "
                        "Создайте новую на странице входа."
                    ), None
                await cursor.execute(
                    "SELECT id FROM users WHERE telegram_user_id=%s "
                    "AND telegram_status='active' AND is_active=1 LIMIT 1",
                    (telegram_user_id,),
                )
                user = await cursor.fetchone()
                if not user:
                    return False, (
                        "Этот Telegram не привязан к активному аккаунту. "
                        "Сначала войдите по паролю и подтвердите Telegram в кабинете."
                    ), None
                if request["approved_at"]:
                    if int(request.get("user_id") or 0) == int(user["id"]):
                        return True, self.auth_approved_text(purpose), request
                    return False, "Запрос уже подтверждён другим аккаунтом.", None
                return True, "Подтвердите действие кнопкой ниже.", request
    async def approve_auth_request(
        self,
        token: str,
        purpose: str,
        telegram_user_id: int,
    ) -> tuple[bool, str]:
        token_hash = hashlib.sha256(token.encode("utf-8")).hexdigest()
        async with self._pool().acquire() as connection:
            await connection.begin()
            try:
                async with connection.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute(
                        "SELECT id,user_id,approved_at FROM telegram_auth_requests "
                        "WHERE token_hash=%s AND purpose=%s AND consumed_at IS NULL "
                        "AND expires_at>NOW() LIMIT 1 FOR UPDATE",
                        (token_hash, purpose),
                    )
                    request = await cursor.fetchone()
                    if not request:
                        await connection.rollback()
                        return False, (
                            "Ссылка недействительна или уже использована. "
                            "Создайте новую на странице входа."
                        )
                    await cursor.execute(
                        "SELECT id,role FROM users WHERE telegram_user_id=%s "
                        "AND telegram_status='active' AND is_active=1 LIMIT 1",
                        (telegram_user_id,),
                    )
                    user = await cursor.fetchone()
                    if not user:
                        await connection.rollback()
                        return False, (
                            "Этот Telegram не привязан к активному аккаунту. "
                            "Сначала войдите по паролю и подтвердите Telegram в кабинете."
                        )
                    if request["approved_at"]:
                        await connection.rollback()
                        if int(request.get("user_id") or 0) == int(user["id"]):
                            return True, self.auth_approved_text(purpose)
                        return False, "Запрос уже подтверждён другим аккаунтом."
                    await cursor.execute(
                        "UPDATE telegram_auth_requests "
                        "SET user_id=%s,approved_at=NOW() "
                        "WHERE id=%s AND approved_at IS NULL AND consumed_at IS NULL",
                        (user["id"], request["id"]),
                    )
                    if cursor.rowcount != 1:
                        await connection.rollback()
                        return False, "Запрос уже обработан. Создайте новый на странице входа."
                    await cursor.execute(
                        "INSERT INTO audit_log "
                        "(user_id,role,action,entity_type,entity_id,result,metadata) "
                        "VALUES (%s,%s,'auth.telegram_request_approved',"
                        "'telegram_auth_request',%s,'success',%s)",
                        (
                            user["id"],
                            user["role"],
                            request["id"],
                            json.dumps({"purpose": purpose}, ensure_ascii=False),
                        ),
                    )
                await connection.commit()
                return True, self.auth_approved_text(purpose)
            except Exception:
                await connection.rollback()
                raise
    async def cancel_auth_request(
        self,
        token: str,
        purpose: str,
        telegram_user_id: int,
    ) -> tuple[bool, str]:
        token_hash = hashlib.sha256(token.encode("utf-8")).hexdigest()
        async with self._pool().acquire() as connection:
            await connection.begin()
            try:
                async with connection.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute(
                        "SELECT id,approved_at FROM telegram_auth_requests "
                        "WHERE token_hash=%s AND purpose=%s AND consumed_at IS NULL "
                        "AND expires_at>NOW() LIMIT 1 FOR UPDATE",
                        (token_hash, purpose),
                    )
                    request = await cursor.fetchone()
                    if not request:
                        await connection.rollback()
                        return False, "Запрос уже недействителен."
                    await cursor.execute(
                        "SELECT id,role FROM users WHERE telegram_user_id=%s "
                        "AND telegram_status='active' AND is_active=1 LIMIT 1",
                        (telegram_user_id,),
                    )
                    user = await cursor.fetchone()
                    if not user:
                        await connection.rollback()
                        return False, "Telegram не привязан к активному аккаунту."
                    if request["approved_at"]:
                        await connection.rollback()
                        return False, "Подтверждённый запрос уже нельзя отменить."
                    await cursor.execute(
                        "UPDATE telegram_auth_requests SET consumed_at=NOW() "
                        "WHERE id=%s AND approved_at IS NULL AND consumed_at IS NULL",
                        (request["id"],),
                    )
                    if cursor.rowcount != 1:
                        await connection.rollback()
                        return False, "Запрос уже обработан."
                    await cursor.execute(
                        "INSERT INTO audit_log "
                        "(user_id,role,action,entity_type,entity_id,result,metadata) "
                        "VALUES (%s,%s,'auth.telegram_request_cancelled',"
                        "'telegram_auth_request',%s,'success',%s)",
                        (
                            user["id"],
                            user["role"],
                            request["id"],
                            json.dumps({"purpose": purpose}, ensure_ascii=False),
                        ),
                    )
                await connection.commit()
                return True, "Запрос отменён. Вход или сброс пароля не выполнен."
            except Exception:
                await connection.rollback()
                raise
    @staticmethod
    def auth_approved_text(purpose: str) -> str:
        if purpose == "password_reset":
            return (
                "Сброс пароля подтверждён. Вернитесь в браузер — "
                "там откроется безопасная форма нового пароля."
            )
        return "Вход подтверждён. Вернитесь в браузер — кабинет откроется автоматически."
    async def resume_invites_for_user(self, user_id: int) -> int:
        return await self.execute(
            "UPDATE notification_queue SET status='retry',attempts=0,"
            "next_attempt_at=NOW(),locked_at=NULL,locked_by=NULL,"
            "failed_at=NULL,last_error=NULL "
            "WHERE event_type='telegram.invite.create' "
            "AND status IN ('pending','retry','failed') "
            "AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.user_id')) "
            "AS UNSIGNED)=%s",
            (user_id,),
        )
    async def orders_for_user(self, user: dict[str, Any], limit: int = 8) -> list[dict[str, Any]]:
        role = user["role"]
        user_id = int(user["id"])
        if role == "client":
            where, params = "o.client_id=%s", (user_id,)
        elif role == "manager":
            where, params = "o.manager_id=%s", (user_id,)
        elif role == "organizer":
            where, params = "o.organizer_id=%s", (user_id,)
        elif role == "admin":
            where, params = "1=1", ()
        else:
            where, params = "EXISTS (SELECT 1 FROM order_team ot WHERE ot.order_id=o.id AND ot.worker_id=%s)", (user_id,)
        return await self.fetch_all(
            f"SELECT o.id,o.title,o.event_type,o.event_date,o.start_time,o.status,"
            f"o.venue,o.address,u.first_name organizer_first_name,u.last_name organizer_last_name "
            f"FROM orders o LEFT JOIN users u ON u.id=o.organizer_id "
            f"WHERE {where} ORDER BY o.event_date IS NULL,o.event_date DESC,o.id DESC "
            f"LIMIT {max(1, min(20, limit))}",
            params,
        )
    async def client_leads(self, user_id: int, limit: int = 8) -> list[dict[str, Any]]:
        return await self.fetch_all(
            "SELECT id,event_type,event_date,status,created_at FROM leads "
            "WHERE client_id=%s ORDER BY id DESC LIMIT %s",
            (user_id, max(1, min(20, limit))),
        )
    async def order_for_user(
        self,
        user: dict[str, Any],
        order_id: int,
    ) -> dict[str, Any] | None:
        role = str(user["role"])
        user_id = int(user["id"])
        if role == "client":
            restriction, params = "o.client_id=%s", (user_id,)
        elif role == "manager":
            restriction, params = "o.manager_id=%s", (user_id,)
        elif role == "organizer":
            restriction, params = "o.organizer_id=%s", (user_id,)
        elif role == "admin":
            restriction, params = "1=1", ()
        else:
            restriction = (
                "EXISTS (SELECT 1 FROM order_team ot WHERE ot.order_id=o.id "
                "AND ot.worker_id=%s AND ot.participation_status "
                "IN ('confirmed','on_location','completed'))"
            )
            params = (user_id,)
        return await self.fetch_one(
            "SELECT o.id,o.title,o.event_type,o.event_date,o.start_time,o.end_time,"
            "o.status,o.venue,o.address,o.guests_count,"
            "u.first_name organizer_first_name,u.last_name organizer_last_name "
            "FROM orders o LEFT JOIN users u ON u.id=o.organizer_id "
            f"WHERE o.id=%s AND {restriction} LIMIT 1",
            (order_id, *params),
        )
    async def staff_assignments(self, user_id: int, pending_only: bool = False) -> list[dict[str, Any]]:
        status_clause = "AND es.status='pending'" if pending_only else (
            "AND es.status IN ('confirmed','on_location','completed')"
        )
        return await self.fetch_all(
            "SELECT es.id assignment_id,es.status,e.id event_id,e.title,e.event_date,"
            "e.start_time,e.end_time,COALESCE(NULLIF(o.venue,''),NULLIF(o.address,''),"
            "NULLIF(e.location,''),'Адрес уточняется') location,o.id order_id "
            "FROM event_staff es JOIN events e ON e.id=es.event_id "
            "LEFT JOIN orders o ON o.event_id=e.id "
            f"WHERE es.worker_id=%s {status_clause} "
            "ORDER BY e.event_date,e.start_time,es.id LIMIT 30",
            (user_id,),
        )
    async def staff_tasks(self, user_id: int, today_only: bool = False) -> list[dict[str, Any]]:
        date_clause = "AND t.due_date=CURDATE()" if today_only else ""
        return await self.fetch_all(
            "SELECT t.id,t.title,t.status,t.due_date,o.id order_id,o.title order_title "
            "FROM order_tasks t JOIN orders o ON o.id=t.order_id "
            "JOIN order_team ot ON ot.order_id=o.id AND ot.worker_id=%s "
            "WHERE t.assigned_to=%s AND ot.participation_status IN ('confirmed','on_location','completed') "
            f"{date_clause} ORDER BY t.due_date IS NULL,t.due_date,t.id LIMIT 30",
            (user_id, user_id),
        )
    async def manager_leads(self, user_id: int, only_new: bool) -> list[dict[str, Any]]:
        status = "AND l.status='new'" if only_new else ""
        return await self.fetch_all(
            "SELECT l.id,l.client_name,l.event_type,l.event_date,l.status,l.created_at "
            "FROM leads l WHERE (l.manager_id=%s OR (l.manager_id IS NULL AND l.status='new')) "
            f"{status} ORDER BY l.id DESC LIMIT 20",
            (user_id,),
        )
    async def register_group(
        self,
        telegram_chat_id: int,
        kind: str,
        title: str,
        rights: dict[str, bool],
    ) -> tuple[bool, str]:
        if kind not in {"client", "team"}:
            return False, "Укажите тип группы: client или team."
        try:
            async with self._pool().acquire() as connection:
                await connection.begin()
                try:
                    async with connection.cursor(aiomysql.DictCursor) as cursor:
                        await cursor.execute(
                            "SELECT status,bound_order_id FROM telegram_group_pool "
                            "WHERE telegram_chat_id=%s LIMIT 1 FOR UPDATE",
                            (telegram_chat_id,),
                        )
                        existing = await cursor.fetchone()
                        if existing and (
                            existing.get("status") != "available"
                            or existing.get("bound_order_id") is not None
                        ):
                            await connection.rollback()
                            message = (
                                "Группа уже использовалась или закреплена за заказом "
                                "и не может быть возвращена в пул."
                            )
                            return False, message
                        values = (
                            kind,
                            title[:255],
                            int(rights.get("is_admin", False)),
                            int(rights.get("can_invite_users", False)),
                            int(rights.get("can_delete_messages", False)),
                            int(rights.get("can_manage_chat", False)),
                            int(rights.get("can_change_info", False)),
                            int(rights.get("can_restrict_members", False)),
                        )
                        if existing:
                            await cursor.execute(
                                "UPDATE telegram_group_pool SET kind=%s,title=%s,"
                                "bot_is_admin=%s,can_invite_users=%s,"
                                "can_delete_messages=%s,can_manage_chat=%s,"
                                "can_change_info=%s,can_restrict_members=%s,"
                                "last_checked_at=NOW(),last_error=NULL "
                                "WHERE telegram_chat_id=%s AND status='available' "
                                "AND bound_order_id IS NULL",
                                values + (telegram_chat_id,),
                            )
                        else:
                            await cursor.execute(
                                "INSERT INTO telegram_group_pool "
                                "(kind,title,bot_is_admin,can_invite_users,"
                                "can_delete_messages,can_manage_chat,can_change_info,"
                                "can_restrict_members,telegram_chat_id,status,"
                                "last_checked_at) "
                                "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,"
                                "'available',NOW())",
                                values + (telegram_chat_id,),
                            )
                    await connection.commit()
                except Exception:
                    await connection.rollback()
                    raise
        except Exception as error:
            LOGGER.exception(
                "Unable to register Telegram group (kind=%s, rights_checked=%s)",
                kind,
                bool(rights),
            )
            return False, f"Не удалось зарегистрировать группу: {type(error).__name__}"
        return True, f"Группа зарегистрирована в пуле как {kind}."
    async def binding_for_telegram_chat(self, telegram_chat_id: int) -> dict[str, Any] | None:
        return await self.fetch_one(
            "SELECT * FROM telegram_chat_bindings "
            "WHERE telegram_chat_id=%s AND status='active' LIMIT 1",
            (telegram_chat_id,),
        )
    async def join_request_context(
        self,
        telegram_chat_id: int,
        telegram_user_id: int,
        invite_link: str,
    ) -> dict[str, Any] | None:
        if not invite_link:
            return None
        async with self._pool().acquire() as connection:
            await connection.begin()
            try:
                async with connection.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute(
                        "SELECT b.id binding_id,b.site_chat_id,b.order_id,b.chat_kind,"
                        "m.id membership_id,m.user_id,m.status membership_status,"
                        "u.role,i.id invite_id "
                        "FROM telegram_chat_bindings b "
                        "JOIN telegram_group_memberships m ON m.binding_id=b.id "
                        "JOIN users u ON u.id=m.user_id AND u.is_active=1 "
                        "JOIN chat_participants cp "
                        "ON cp.chat_id=b.site_chat_id AND cp.user_id=u.id "
                        "LEFT JOIN telegram_invites i ON i.id=m.invite_id "
                        "WHERE b.telegram_chat_id=%s AND b.status='active' "
                        "AND m.telegram_user_id=%s "
                        "AND m.status IN ('invited','join_requested') "
                        "AND i.invite_link=%s AND i.status='pending' "
                        "AND i.expires_at>NOW() LIMIT 1 FOR UPDATE",
                        (telegram_chat_id, telegram_user_id, invite_link),
                    )
                    context = await cursor.fetchone()
                    if not context:
                        await connection.rollback()
                        return None
                    await cursor.execute(
                        "UPDATE telegram_group_memberships "
                        "SET status='join_requested' WHERE id=%s "
                        "AND status IN ('invited','join_requested')",
                        (context["membership_id"],),
                    )
                await connection.commit()
                context["membership_status"] = "join_requested"
                return context
            except Exception:
                await connection.rollback()
                raise
    async def rollback_join_request(self, membership_id: int) -> None:
        await self.execute(
            "UPDATE telegram_group_memberships m "
            "LEFT JOIN telegram_invites i ON i.id=m.invite_id "
            "SET m.status='invited' "
            "WHERE m.id=%s AND m.status='join_requested' "
            "AND i.status='pending' AND i.expires_at>NOW()",
            (membership_id,),
        )
    async def membership_for_telegram_identity(
        self,
        telegram_chat_id: int,
        telegram_user_id: int,
    ) -> dict[str, Any] | None:
        return await self.fetch_one(
            "SELECT m.id,m.status,m.user_id,u.role,b.chat_kind "
            "FROM telegram_chat_bindings b "
            "JOIN telegram_group_memberships m ON m.binding_id=b.id "
            "JOIN users u ON u.id=m.user_id AND u.is_active=1 "
            "JOIN chat_participants cp "
            "ON cp.chat_id=b.site_chat_id AND cp.user_id=u.id "
            "WHERE b.telegram_chat_id=%s AND b.status='active' "
            "AND m.telegram_user_id=%s LIMIT 1",
            (telegram_chat_id, telegram_user_id),
        )
    async def membership_joined(
        self,
        membership_id: int,
        invite_id: int | None,
        payload: dict[str, Any],
    ) -> None:
        async with self._pool().acquire() as connection:
            await connection.begin()
            try:
                async with connection.cursor() as cursor:
                    await cursor.execute(
                        "UPDATE telegram_group_memberships SET status='joined',joined_at=NOW(),"
                        "left_at=NULL,removed_at=NULL WHERE id=%s",
                        (membership_id,),
                    )
                    if invite_id:
                        await cursor.execute(
                            "UPDATE telegram_invites SET status='used',used_at=NOW() WHERE id=%s",
                            (invite_id,),
                        )
                    await cursor.execute(
                        "INSERT IGNORE INTO telegram_inbound_events "
                        "(idempotency_key,event_type,telegram_chat_id,telegram_user_id,"
                        "payload_json,status,next_attempt_at) "
                        "VALUES (%s,'membership',%s,%s,%s,'pending',NOW())",
                        (
                            str(payload.get("event_key") or (
                                "membership:"
                                f"{int(payload.get('telegram_chat_id') or 0)}:"
                                f"{int(payload.get('telegram_user_id') or 0)}:joined"
                            ))[:190],
                            int(payload.get("telegram_chat_id") or 0),
                            int(payload.get("telegram_user_id") or 0),
                            json.dumps(payload, ensure_ascii=False),
                        ),
                    )
                await connection.commit()
            except Exception:
                await connection.rollback()
                raise
    async def membership_left(
        self,
        telegram_chat_id: int,
        telegram_user_id: int,
        payload: dict[str, Any],
    ) -> bool:
        async with self._pool().acquire() as connection:
            await connection.begin()
            try:
                async with connection.cursor() as cursor:
                    await cursor.execute(
                        "UPDATE telegram_group_memberships m "
                        "JOIN telegram_chat_bindings b ON b.id=m.binding_id "
                        "SET m.status='left',m.left_at=NOW() "
                        "WHERE b.telegram_chat_id=%s AND m.telegram_user_id=%s",
                        (telegram_chat_id, telegram_user_id),
                    )
                    if cursor.rowcount <= 0:
                        await connection.commit()
                        return False
                    await cursor.execute(
                        "INSERT IGNORE INTO telegram_inbound_events "
                        "(idempotency_key,event_type,telegram_chat_id,telegram_user_id,"
                        "payload_json,status,next_attempt_at) "
                        "VALUES (%s,'membership',%s,%s,%s,'pending',NOW())",
                        (
                            str(payload.get("event_key") or (
                                f"membership:{telegram_chat_id}:{telegram_user_id}:left"
                            ))[:190],
                            telegram_chat_id,
                            telegram_user_id,
                            json.dumps(payload, ensure_ascii=False),
                        ),
                    )
                await connection.commit()
                return True
            except Exception:
                await connection.rollback()
                raise
    async def reserve_group(self, order_id: int, chat_kind: str) -> dict[str, Any] | None:
        pool_kind = "client" if chat_kind == "order_client" else "team"
        async with self._pool().acquire() as connection:
            await connection.begin()
            try:
                async with connection.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute(
                        "SELECT b.*,p.title pool_title,o.title order_title,o.event_date,"
                        "u.first_name client_first_name,u.last_name client_last_name "
                        "FROM telegram_chat_bindings b "
                        "JOIN telegram_group_pool p ON p.id=b.group_pool_id "
                        "JOIN orders o ON o.id=b.order_id "
                        "LEFT JOIN users u ON u.id=o.client_id "
                        "WHERE b.order_id=%s AND b.chat_kind=%s LIMIT 1 FOR UPDATE",
                        (order_id, chat_kind),
                    )
                    existing = await cursor.fetchone()
                    if existing:
                        await connection.commit()
                        return existing
                    await cursor.execute(
                        "SELECT c.id site_chat_id,o.title order_title,o.event_date,"
                        "u.first_name client_first_name,u.last_name client_last_name "
                        "FROM chats c JOIN orders o ON o.id=c.order_id "
                        "LEFT JOIN users u ON u.id=o.client_id "
                        "WHERE c.order_id=%s AND c.type=%s LIMIT 1 FOR UPDATE",
                        (order_id, chat_kind),
                    )
                    site = await cursor.fetchone()
                    if not site:
                        await connection.rollback()
                        return None
                    await cursor.execute(
                        "SELECT * FROM telegram_group_pool "
                        "WHERE kind=%s AND status='available' AND bot_is_admin=1 "
                        "AND can_invite_users=1 AND can_delete_messages=1 "
                        "AND can_manage_chat=1 AND can_change_info=1 "
                        "AND can_restrict_members=1 "
                        "ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED",
                        (pool_kind,),
                    )
                    pool = await cursor.fetchone()
                    if not pool:
                        await connection.rollback()
                        return None
                    await cursor.execute(
                        "UPDATE telegram_group_pool SET status='reserved',bound_order_id=%s,"
                        "reserved_at=NOW(),last_error=NULL WHERE id=%s AND status='available'",
                        (order_id, pool["id"]),
                    )
                    if cursor.rowcount != 1:
                        await connection.rollback()
                        return None
                    await cursor.execute(
                        "INSERT INTO telegram_chat_bindings "
                        "(site_chat_id,order_id,group_pool_id,telegram_chat_id,chat_kind,status) "
                        "VALUES (%s,%s,%s,%s,%s,'reserved')",
                        (
                            site["site_chat_id"],
                            order_id,
                            pool["id"],
                            pool["telegram_chat_id"],
                            chat_kind,
                        ),
                    )
                    binding_id = cursor.lastrowid
                await connection.commit()
                return {
                    **pool,
                    **site,
                    "id": binding_id,
                    "binding_id": binding_id,
                    "group_pool_id": pool["id"],
                    "order_id": order_id,
                    "chat_kind": chat_kind,
                    "status": "reserved",
                }
            except Exception:
                await connection.rollback()
                raise
    async def activate_binding(self, binding_id: int) -> None:
        updated = await self.execute(
            "UPDATE telegram_chat_bindings b "
            "JOIN telegram_group_pool p ON p.id=b.group_pool_id "
            "SET b.status='active',b.activated_at=COALESCE(b.activated_at,NOW()),"
            "b.last_sync_at=NOW(),b.last_error=NULL,p.status='active',"
            "p.activated_at=COALESCE(p.activated_at,NOW()),p.last_error=NULL "
            "WHERE b.id=%s AND b.status<>'archived' AND p.status<>'archived'",
            (binding_id,),
        )
        if updated <= 0:
            state = await self.fetch_one(
                "SELECT b.status binding_status,p.status pool_status "
                "FROM telegram_chat_bindings b "
                "JOIN telegram_group_pool p ON p.id=b.group_pool_id "
                "WHERE b.id=%s LIMIT 1",
                (binding_id,),
            )
            if (
                not state
                or state["binding_status"] == "archived"
                or state["pool_status"] == "archived"
            ):
                raise RuntimeError("Telegram binding cannot be activated")
    async def binding_error(self, binding_id: int, error: str) -> None:
        await self.execute(
            "UPDATE telegram_chat_bindings b "
            "JOIN telegram_group_pool p ON p.id=b.group_pool_id "
            "SET b.status='broken',b.last_error=%s,p.status='broken',p.last_error=%s "
            "WHERE b.id=%s AND b.status<>'archived'",
            (error[:2000], error[:2000], binding_id),
        )
    async def invite_context(
        self,
        order_id: int,
        user_id: int,
        chat_kind: str,
    ) -> dict[str, Any] | None:
        row = await self.fetch_one(
            "SELECT b.*,u.telegram_user_id,u.telegram_chat_id,u.first_name,u.last_name,u.role "
            "FROM telegram_chat_bindings b JOIN users u ON u.id=%s "
            "JOIN chat_participants cp ON cp.chat_id=b.site_chat_id AND cp.user_id=u.id "
            "WHERE b.order_id=%s AND b.chat_kind=%s AND b.status='active' "
            "AND u.is_active=1 AND u.telegram_user_id IS NOT NULL LIMIT 1",
            (user_id, order_id, chat_kind),
        )
        allowed_roles = {
            "order_client": {"client", "organizer", "admin"},
            "order_team": {
                "organizer",
                "florist",
                "courier",
                "installer",
                "helper",
                "worker",
                "admin",
            },
        }
        if not row or str(row.get("role") or "") not in allowed_roles.get(chat_kind, set()):
            return None
        return row
    async def pending_invites(self, binding_id: int, user_id: int) -> list[dict[str, Any]]:
        return await self.fetch_all(
            "SELECT * FROM telegram_invites WHERE binding_id=%s AND user_id=%s "
            "AND status='pending' AND expires_at>NOW() ORDER BY id DESC",
            (binding_id, user_id),
        )
    async def save_invite(
        self,
        binding: dict[str, Any],
        user_id: int,
        invite_link: str,
        invite_name: str,
        ttl_minutes: int,
    ) -> int:
        async with self._pool().acquire() as connection:
            await connection.begin()
            try:
                async with connection.cursor() as cursor:
                    await cursor.execute(
                        "UPDATE telegram_invites SET status='revoked',revoked_at=NOW() "
                        "WHERE binding_id=%s AND user_id=%s AND status='pending'",
                        (binding["id"], user_id),
                    )
                    await cursor.execute(
                        "INSERT INTO telegram_invites "
                        "(binding_id,user_id,telegram_user_id,invite_link,telegram_invite_name,"
                        "status,expires_at) VALUES (%s,%s,%s,%s,%s,'pending',"
                        "DATE_ADD(NOW(),INTERVAL %s MINUTE))",
                        (
                            binding["id"],
                            user_id,
                            binding["telegram_user_id"],
                            invite_link,
                            invite_name[:120],
                            ttl_minutes,
                        ),
                    )
                    invite_id = cursor.lastrowid
                    await cursor.execute(
                        "INSERT INTO telegram_group_memberships "
                        "(binding_id,user_id,telegram_user_id,membership_role,status,invite_id) "
                        "VALUES (%s,%s,%s,%s,'invited',%s) AS new "
                        "ON DUPLICATE KEY UPDATE telegram_user_id=new.telegram_user_id,"
                        "membership_role=new.membership_role,status='invited',"
                        "invite_id=new.invite_id,left_at=NULL,removed_at=NULL",
                        (
                            binding["id"],
                            user_id,
                            binding["telegram_user_id"],
                            binding["role"],
                            invite_id,
                        ),
                    )
                await connection.commit()
                return int(invite_id)
            except Exception:
                await connection.rollback()
                raise
    async def message_sync_context(self, site_message_id: int) -> dict[str, Any] | None:
        row = await self.fetch_one(
            "SELECT m.*,c.order_id,c.type chat_kind,b.id binding_id,b.telegram_chat_id,"
            "author.first_name sender_first_name,author.last_name sender_last_name,"
            "author.role sender_role,"
            "rm.telegram_message_id reply_telegram_message_id,"
            "reply_source.message reply_message,"
            "own_map.telegram_message_id mapped_telegram_message_id "
            "FROM messages m JOIN chats c ON c.id=m.chat_id "
            "JOIN telegram_chat_bindings b ON b.site_chat_id=c.id AND b.status='active' "
            "LEFT JOIN users author ON author.id=m.user_id "
            "LEFT JOIN messages reply_source ON reply_source.id=m.reply_to_message_id "
            "AND reply_source.chat_id=m.chat_id "
            "LEFT JOIN telegram_message_map rm ON rm.site_message_id=m.reply_to_message_id "
            "AND rm.binding_id=b.id "
            "LEFT JOIN telegram_message_map own_map ON own_map.site_message_id=m.id "
            "AND own_map.binding_id=b.id "
            "WHERE m.id=%s AND m.deleted_at IS NULL LIMIT 1",
            (site_message_id,),
        )
        if not row:
            return None
        attachments = await self.fetch_all(
            "SELECT * FROM message_attachments WHERE message_id=%s ORDER BY id",
            (site_message_id,),
        )
        row["attachments"] = attachments
        return row
    async def message_map(
        self,
        message: dict[str, Any],
        telegram_message_id: int,
        direction: str,
    ) -> None:
        await self.execute(
            "INSERT INTO telegram_message_map "
            "(site_message_id,site_chat_id,binding_id,telegram_chat_id,telegram_message_id,"
            "direction,revision,sync_status) VALUES (%s,%s,%s,%s,%s,%s,%s,'mapped') AS new "
            "ON DUPLICATE KEY UPDATE telegram_message_id=new.telegram_message_id,"
            "revision=new.revision,sync_status='mapped',last_error=NULL",
            (
                message["id"],
                message["chat_id"],
                message["binding_id"],
                message["telegram_chat_id"],
                telegram_message_id,
                direction,
                int(message.get("revision") or 1),
            ),
        )
    async def mapped_message(self, site_message_id: int) -> dict[str, Any] | None:
        return await self.fetch_one(
            "SELECT tm.*,m.message,m.revision,m.created_at,b.status binding_status,"
            "author.first_name sender_first_name,author.last_name sender_last_name,"
            "author.role sender_role,"
            "(SELECT ma.attachment_type FROM message_attachments ma "
            " WHERE ma.message_id=m.id ORDER BY ma.id LIMIT 1) attachment_type "
            "FROM telegram_message_map tm JOIN messages m ON m.id=tm.site_message_id "
            "JOIN telegram_chat_bindings b ON b.id=tm.binding_id "
            "LEFT JOIN users author ON author.id=m.user_id "
            "WHERE tm.site_message_id=%s LIMIT 1",
            (site_message_id,),
        )
    async def removal_context(
        self,
        order_id: int,
        user_id: int,
        chat_kind: str,
    ) -> dict[str, Any] | None:
        return await self.fetch_one(
            "SELECT b.id binding_id,b.telegram_chat_id,m.id membership_id,"
            "m.telegram_user_id,m.invite_id "
            "FROM telegram_chat_bindings b "
            "JOIN telegram_group_memberships m ON m.binding_id=b.id AND m.user_id=%s "
            "WHERE b.order_id=%s AND b.chat_kind=%s LIMIT 1",
            (user_id, order_id, chat_kind),
        )
    async def membership_removed(self, membership_id: int) -> None:
        await self.execute(
            "UPDATE telegram_group_memberships SET status='removed',removed_at=NOW() WHERE id=%s",
            (membership_id,),
        )
    async def operation_complete(
        self,
        operation_id: int | None,
        telegram_message_id: int | None = None,
    ) -> None:
        if not operation_id:
            return
        await self.execute(
            "UPDATE telegram_sync_operations SET status='completed',completed_at=NOW(),"
            "telegram_message_id=%s,last_error=NULL WHERE id=%s",
            (telegram_message_id, operation_id),
        )
    async def operation_failed(self, operation_id: int | None, error: str) -> None:
        if not operation_id:
            return
        await self.execute(
            "UPDATE telegram_sync_operations SET status='retry',attempts=attempts+1,"
            "last_error=%s WHERE id=%s",
            (error[:2000], operation_id),
        )
    async def operation_waiting_for_group(
        self,
        operation_id: int | None,
        error: str,
    ) -> None:
        if not operation_id:
            return
        await self.execute(
            "UPDATE telegram_sync_operations "
            "SET status='waiting_for_group',attempts=attempts+1,last_error=%s "
            "WHERE id=%s",
            (error[:2000], operation_id),
        )
    async def operation_cancelled(
        self,
        operation_id: int | None,
        error: str,
    ) -> None:
        if not operation_id:
            return
        await self.execute(
            "UPDATE telegram_sync_operations "
            "SET status='failed',attempts=attempts+1,last_error=%s WHERE id=%s",
            (error[:2000], operation_id),
        )
    async def expired_invites(self, limit: int = 200) -> list[dict[str, Any]]:
        return await self.fetch_all(
            "SELECT i.id,i.invite_link,i.binding_id,b.telegram_chat_id,m.id membership_id "
            "FROM telegram_invites i "
            "JOIN telegram_chat_bindings b ON b.id=i.binding_id "
            "LEFT JOIN telegram_group_memberships m ON m.invite_id=i.id "
            "WHERE i.status='pending' AND i.expires_at<=NOW() "
            "ORDER BY i.id LIMIT %s",
            (max(1, min(500, limit)),),
        )
    async def invite_expired(self, invite_id: int, membership_id: int | None) -> None:
        async with self._pool().acquire() as connection:
            await connection.begin()
            try:
                async with connection.cursor() as cursor:
                    await cursor.execute(
                        "UPDATE telegram_invites SET status='expired' "
                        "WHERE id=%s AND status='pending'",
                        (invite_id,),
                    )
                    if membership_id:
                        await cursor.execute(
                            "UPDATE telegram_group_memberships SET status='expired' "
                            "WHERE id=%s AND status='invited'",
                            (membership_id,),
                        )
                await connection.commit()
            except Exception:
                await connection.rollback()
                raise
    async def memberships_for_reconciliation(self, limit: int = 200) -> list[dict[str, Any]]:
        return await self.fetch_all(
            "SELECT m.id,m.status,m.telegram_user_id,b.telegram_chat_id "
            "FROM telegram_group_memberships m "
            "JOIN telegram_chat_bindings b ON b.id=m.binding_id "
            "WHERE b.status='active' AND m.status IN ('joined','invited','join_requested') "
            "ORDER BY m.updated_at LIMIT %s",
            (max(1, min(500, limit)),),
        )
    async def reconcile_membership_status(self, membership_id: int, status: str) -> None:
        if status == "joined":
            await self.execute(
                "UPDATE telegram_group_memberships SET status='joined',"
                "joined_at=COALESCE(joined_at,NOW()),left_at=NULL,removed_at=NULL WHERE id=%s",
                (membership_id,),
            )
        elif status == "left":
            await self.execute(
                "UPDATE telegram_group_memberships SET status='left',left_at=NOW() WHERE id=%s",
                (membership_id,),
            )
    async def active_bindings_for_title_reconciliation(
        self,
        limit: int = 100,
    ) -> list[dict[str, Any]]:
        return await self.fetch_all(
            "SELECT b.id,b.telegram_chat_id,b.order_id,b.chat_kind,"
            "u.first_name client_first_name,u.last_name client_last_name "
            "FROM telegram_chat_bindings b "
            "JOIN orders o ON o.id=b.order_id "
            "LEFT JOIN users u ON u.id=o.client_id "
            "WHERE b.status='active' ORDER BY b.id LIMIT %s",
            (max(1, min(300, limit)),),
        )
    async def bindings_to_archive(self, limit: int = 100) -> list[dict[str, Any]]:
        return await self.fetch_all(
            "SELECT b.id,b.telegram_chat_id,b.group_pool_id,b.order_id,b.chat_kind "
            "FROM telegram_chat_bindings b JOIN orders o ON o.id=b.order_id "
            "WHERE b.status='active' AND o.status IN ('completed','cancelled') "
            "ORDER BY b.id LIMIT %s",
            (max(1, min(300, limit)),),
        )
    async def archive_binding(self, binding_id: int, pool_id: int) -> None:
        async with self._pool().acquire() as connection:
            await connection.begin()
            try:
                async with connection.cursor() as cursor:
                    await cursor.execute(
                        "UPDATE telegram_chat_bindings SET status='archived',archived_at=NOW() "
                        "WHERE id=%s AND status='active'",
                        (binding_id,),
                    )
                    await cursor.execute(
                        "UPDATE telegram_group_pool SET status='archived',archived_at=NOW() "
                        "WHERE id=%s",
                        (pool_id,),
                    )
                    await cursor.execute(
                        "UPDATE telegram_invites SET status='revoked',revoked_at=NOW() "
                        "WHERE binding_id=%s AND status='pending'",
                        (binding_id,),
                    )
                await connection.commit()
            except Exception:
                await connection.rollback()
                raise
    async def claim_notification(self, worker_id: str) -> dict[str, Any] | None:
        async with self._pool().acquire() as connection:
            await connection.begin()
            try:
                async with connection.cursor(aiomysql.DictCursor) as cursor:
                    await cursor.execute(
                        "UPDATE notification_queue q JOIN login_challenges c ON q.idempotency_key=CONCAT('login-challenge-',c.id) "
                        "SET q.status='cancelled',q.failed_at=NOW(),q.last_error='Login code expired or already used',"
                        "q.payload_json=JSON_OBJECT('redacted',TRUE),q.locked_at=NULL,q.locked_by=NULL "
                        "WHERE q.event_type='privileged_login_code' AND q.status IN ('pending','retry','processing') "
                        "AND (c.expires_at<=NOW() OR c.used_at IS NOT NULL)"
                    )
                    await cursor.execute(
                        "UPDATE notification_queue q "
                        "JOIN password_reset_tokens token "
                        "ON q.idempotency_key=CONCAT('password-reset-',token.id) "
                        "SET q.status='cancelled',q.failed_at=NOW(),"
                        "q.last_error='Password reset link expired or already used',"
                        "q.payload_json=JSON_OBJECT('redacted',TRUE),"
                        "q.locked_at=NULL,q.locked_by=NULL "
                        "WHERE q.event_type='password_reset_requested' "
                        "AND q.status IN ('pending','retry','processing') "
                        "AND (token.expires_at<=NOW() OR token.used_at IS NOT NULL)"
                    )
                    await cursor.execute(
                        "SELECT * FROM notification_queue WHERE status IN ('pending','retry') AND next_attempt_at<=NOW() AND attempts<max_attempts ORDER BY priority,id LIMIT 1 FOR UPDATE"
                    )
                    item = await cursor.fetchone()
                    if not item:
                        await connection.rollback()
                        return None
                    await cursor.execute(
                        "UPDATE notification_queue SET status='processing',locked_at=NOW(),locked_by=%s WHERE id=%s",
                        (worker_id, item["id"]),
                    )
                await connection.commit()
                return item
            except Exception:
                await connection.rollback()
                raise
    async def resolve_chat_ids(self, item: dict[str, Any]) -> list[int]:
        if item.get("telegram_chat_id"):
            return [int(item["telegram_chat_id"])]
        recipient_id = item.get("recipient_id")
        if recipient_id:
            row = await self.fetch_one("SELECT telegram_chat_id FROM users WHERE id=%s AND telegram_chat_id IS NOT NULL", (recipient_id,))
            return [int(row["telegram_chat_id"])] if row else []
        fallback = {
            "admin": self.settings.admin_chat_id,
            "manager": self.settings.manager_chat_id,
            "organizer": self.settings.organizer_chat_id,
        }.get(item["recipient_type"])
        return [fallback] if fallback else []
    async def queue_critical_error(self, error: str) -> bool:
        key = hashlib.sha256(
            f"bot-critical|{error}|{datetime.now(timezone.utc).strftime('%Y%m%d%H')}".encode()
        ).hexdigest()
        text = f"Критическая ошибка Telegram-бота: {error}"[:4096]
        inserted = await self.execute(
            "INSERT INTO notification_queue "
            "(recipient_type,telegram_chat_id,event_type,payload_json,status,priority,"
            "next_attempt_at,idempotency_key) "
            "VALUES ('admin',%s,'bot_critical_error',%s,'pending',1,NOW(),%s) "
            "ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
            (self.settings.admin_chat_id, json.dumps({"text": text}, ensure_ascii=False), key),
        )
        await self.execute(
            "INSERT INTO audit_log (action,entity_type,result,metadata) VALUES ('bot.critical_error','telegram_bot','failure',%s)",
            (json.dumps({"error": error[:500]}, ensure_ascii=False),),
        )
        return inserted > 0
    async def queue_security_alert(
        self,
        event: str,
        telegram_chat_id: int,
        telegram_user_id: int,
    ) -> bool:
        chat_fingerprint = identifier_fingerprint(telegram_chat_id)
        user_fingerprint = identifier_fingerprint(telegram_user_id)
        key = hashlib.sha256(
            (
                f"telegram-security|{event}|{telegram_chat_id}|{telegram_user_id}|"
                f"{datetime.now(timezone.utc).strftime('%Y%m%d%H')}"
            ).encode()
        ).hexdigest()
        text = (
            "Событие безопасности Telegram: "
            f"{event}; группа {chat_fingerprint}; пользователь {user_fingerprint}."
        )
        inserted = await self.execute(
            "INSERT INTO notification_queue "
            "(recipient_type,telegram_chat_id,event_type,payload_json,status,priority,"
            "next_attempt_at,idempotency_key) "
            "VALUES ('admin',%s,'telegram_security_alert',%s,'pending',1,NOW(),%s) "
            "ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
            (
                self.settings.admin_chat_id,
                json.dumps({"text": text}, ensure_ascii=False),
                key,
            ),
        )
        if inserted <= 0:
            return False
        await self.execute(
            "INSERT INTO notifications (user_id,type,title,message,link) "
            "SELECT id,'telegram_security','Событие безопасности Telegram',%s,"
            "'/admin/telegram.php' FROM users WHERE role='admin' AND is_active=1",
            (text,),
        )
        await self.execute(
            "INSERT INTO audit_log "
            "(action,entity_type,result,metadata) "
            "VALUES ('telegram.security_alert','telegram_group','failure',%s)",
            (json.dumps({
                "event": event,
                "telegram_chat": chat_fingerprint,
                "telegram_user": user_fingerprint,
            }, ensure_ascii=False),),
        )
        return True

    async def queue_rejected_message_notice(
        self,
        telegram_user_id: int,
        reason: str,
        event_key: str,
    ) -> bool:
        if telegram_user_id <= 0:
            return False
        key = hashlib.sha256(
            f"telegram-message-rejected|{event_key}".encode()
        ).hexdigest()
        text = "Сообщение не синхронизировано: " + reason[:350]
        inserted = await self.execute(
            "INSERT INTO notification_queue "
            "(recipient_type,telegram_chat_id,event_type,payload_json,status,"
            "priority,next_attempt_at,idempotency_key) "
            "VALUES ('telegram_user',%s,'telegram_message_rejected',%s,"
            "'pending',20,NOW(),%s) "
            "ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
            (
                telegram_user_id,
                json.dumps({"text": text}, ensure_ascii=False),
                key,
            ),
        )
        return inserted > 0
    async def check_group_pool_level(self) -> int:
        threshold = self.settings.group_pool_low_threshold
        setting = await self.fetch_one(
            "SELECT setting_value FROM app_settings "
            "WHERE setting_key='telegram.group_pool_low_threshold' LIMIT 1"
        )
        if setting:
            raw_value = setting.get("setting_value")
            try:
                decoded = json.loads(raw_value) if isinstance(raw_value, str) else raw_value
                if decoded is not None:
                    threshold = max(0, min(100, int(decoded)))
            except (TypeError, ValueError, json.JSONDecodeError):
                threshold = self.settings.group_pool_low_threshold
        rows = await self.fetch_all(
            "SELECT kind,COUNT(*) available_count FROM telegram_group_pool "
            "WHERE status='available' GROUP BY kind"
        )
        counts = {"client": 0, "team": 0}
        counts.update({
            str(row["kind"]): int(row["available_count"])
            for row in rows
            if row.get("kind") in counts
        })
        alerts = 0
        for kind, count in counts.items():
            if count > threshold:
                continue
            key = hashlib.sha256(
                (
                    f"telegram-pool-low|{kind}|{count}|"
                    f"{datetime.now(timezone.utc).strftime('%Y%m%d')}"
                ).encode()
            ).hexdigest()
            text = (
                f"В пуле Telegram-групп типа {kind} осталось {count}. "
                f"Порог предупреждения: {threshold}."
            )
            inserted = await self.execute(
                "INSERT INTO notification_queue "
                "(recipient_type,telegram_chat_id,event_type,payload_json,status,priority,"
                "next_attempt_at,idempotency_key) "
                "VALUES ('admin',%s,'telegram_group_pool_low',%s,'pending',2,NOW(),%s) "
                "ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
                (
                    self.settings.admin_chat_id,
                    json.dumps({"text": text}, ensure_ascii=False),
                    key,
                ),
            )
            if inserted:
                alerts += 1
                await self.execute(
                    "INSERT INTO notifications (user_id,type,title,message,link) "
                    "SELECT id,'telegram_pool_low','Заканчиваются Telegram-группы',%s,"
                    "'/admin/group-pool.php' FROM users "
                    "WHERE role='admin' AND is_active=1",
                    (text,),
                )
        return alerts
    async def integer_setting(
        self,
        key: str,
        default: int,
        minimum: int,
        maximum: int,
    ) -> int:
        row = await self.fetch_one(
            "SELECT setting_value FROM app_settings WHERE setting_key=%s LIMIT 1",
            (key,),
        )
        if not row:
            return default
        raw_value = row.get("setting_value")
        try:
            decoded = json.loads(raw_value) if isinstance(raw_value, str) else raw_value
            if decoded is None:
                return default
            return max(minimum, min(maximum, int(decoded)))
        except (TypeError, ValueError, json.JSONDecodeError):
            return default
    async def notification_sent(self, queue_id: int) -> None:
        await self.execute(
            "UPDATE notification_queue SET status='sent',attempts=attempts+1,sent_at=NOW(),locked_at=NULL,locked_by=NULL,last_error=NULL,payload_json=IF(event_type IN ('privileged_login_code','password_reset_requested'),JSON_OBJECT('redacted',TRUE),payload_json) WHERE id=%s",
            (queue_id,),
        )
        await self.execute(
            "INSERT INTO audit_log (action,entity_type,entity_id,result,metadata) VALUES ('notification.sent','notification_queue',%s,'success',%s)",
            (queue_id, json.dumps({"worker": "telegram_bot"})),
        )
    async def notification_recipients_delivered(
        self,
        queue_id: int,
        chat_ids: set[int],
    ) -> None:
        delivered = json.dumps(sorted({int(chat_id) for chat_id in chat_ids}))
        await self.execute(
            "UPDATE notification_queue "
            "SET payload_json=JSON_SET(payload_json,'$._sent_chat_ids',"
            "JSON_EXTRACT(%s,'$')) WHERE id=%s",
            (delivered, queue_id),
        )
    async def notification_cancelled(self, queue_id: int, reason: str) -> None:
        await self.execute(
            "UPDATE notification_queue SET status='cancelled',attempts=attempts+1,failed_at=NOW(),locked_at=NULL,locked_by=NULL,last_error=%s,payload_json=IF(event_type IN ('privileged_login_code','password_reset_requested'),JSON_OBJECT('redacted',TRUE),payload_json) WHERE id=%s",
            (reason[:2000], queue_id),
        )
        await self.execute(
            "INSERT INTO audit_log (action,entity_type,entity_id,result,metadata) VALUES ('notification.cancelled','notification_queue',%s,'failure',%s)",
            (queue_id, json.dumps({"error": reason[:500]}, ensure_ascii=False)),
        )
    async def notification_failed(self, item: dict[str, Any], error: str, retry_after: int | None = None) -> None:
        attempts = int(item.get("attempts", 0)) + 1
        maximum = int(item.get("max_attempts", 6))
        if attempts >= maximum:
            await self.execute(
                "UPDATE notification_queue SET status='failed',attempts=%s,failed_at=NOW(),locked_at=NULL,locked_by=NULL,last_error=%s,payload_json=IF(event_type IN ('privileged_login_code','password_reset_requested'),JSON_OBJECT('redacted',TRUE),payload_json) WHERE id=%s",
                (attempts, error[:2000], item["id"]),
            )
            await self.execute(
                "INSERT INTO audit_log (action,entity_type,entity_id,result,metadata) VALUES ('notification.failed','notification_queue',%s,'failure',%s)",
                (item["id"], json.dumps({"attempts": attempts, "error": error[:500]}, ensure_ascii=False)),
            )
            return
        delay = retry_after if retry_after is not None else min(3600, 15 * (2 ** max(0, attempts - 1)))
        await self.execute(
            "UPDATE notification_queue SET status='retry',attempts=%s,next_attempt_at=DATE_ADD(NOW(),INTERVAL %s SECOND),locked_at=NULL,locked_by=NULL,last_error=%s WHERE id=%s",
            (attempts, int(delay), error[:2000], item["id"]),
        )
        await self.execute(
            "INSERT INTO audit_log (action,entity_type,entity_id,result,metadata) VALUES ('notification.retry_scheduled','notification_queue',%s,'failure',%s)",
            (item["id"], json.dumps({"attempts": attempts, "delay_seconds": int(delay), "error": error[:500]}, ensure_ascii=False)),
        )
    async def recover_stale_notifications(self) -> int:
        return await self.execute(
            "UPDATE notification_queue SET status='retry',locked_at=NULL,locked_by=NULL,last_error='Recovered stale processing lock' WHERE status='processing' AND locked_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)"
        )
    async def cancel_duplicate_suspicious_login_notifications(self) -> int:
        return await self.execute(
            "UPDATE notification_queue duplicate_item "
            "JOIN notification_queue original_item "
            "ON original_item.idempotency_key=duplicate_item.idempotency_key "
            "AND original_item.id<duplicate_item.id "
            "AND original_item.event_type='suspicious_login' "
            "SET duplicate_item.status='cancelled',"
            "duplicate_item.failed_at=NOW(),duplicate_item.locked_at=NULL,"
            "duplicate_item.locked_by=NULL,"
            "duplicate_item.last_error='Duplicate suspicious login notification suppressed' "
            "WHERE duplicate_item.event_type='suspicious_login' "
            "AND duplicate_item.status IN ('pending','retry','processing')"
        )
    async def heartbeat(self, state: dict[str, Any]) -> None:
        payload = json.dumps(state, ensure_ascii=False)
        await self.execute(
            "INSERT INTO bot_state (state_key,state_value) "
            "VALUES ('heartbeat',%s) AS new "
            "ON DUPLICATE KEY UPDATE state_value=new.state_value,updated_at=NOW()",
            (payload,),
        )
    async def maintenance(self) -> dict[str, int]:
        scheduled = await self.queue_scheduled_notifications()
        overdue = await self.queue_overdue_payments()
        task_reminders = await self.queue_task_reminders()
        timeline_reminders = await self.queue_timeline_reminders()
        lead_reminders = await self.queue_lead_reminders()
        expired_login_notifications = await self.execute(
            "UPDATE notification_queue q JOIN login_challenges c ON q.idempotency_key=CONCAT('login-challenge-',c.id) SET q.last_error=IF(q.status IN ('pending','retry','processing'),'Login code expired',q.last_error),q.status=IF(q.status IN ('pending','retry','processing'),'cancelled',q.status),q.payload_json=JSON_OBJECT('redacted',TRUE),q.locked_at=NULL,q.locked_by=NULL WHERE q.event_type='privileged_login_code' AND c.expires_at<=NOW()"
        )
        expired_password_reset_notifications = await self.execute(
            "UPDATE notification_queue q "
            "JOIN password_reset_tokens token "
            "ON q.idempotency_key=CONCAT('password-reset-',token.id) "
            "SET q.last_error=IF(q.status IN ('pending','retry','processing'),"
            "'Password reset link expired',q.last_error),"
            "q.status=IF(q.status IN ('pending','retry','processing'),'cancelled',q.status),"
            "q.payload_json=JSON_OBJECT('redacted',TRUE),q.locked_at=NULL,q.locked_by=NULL "
            "WHERE q.event_type='password_reset_requested' "
            "AND (token.used_at IS NOT NULL OR token.expires_at<=NOW())"
        )
        expired_links = await self.execute("DELETE FROM telegram_link_tokens WHERE expires_at<DATE_SUB(NOW(),INTERVAL 1 DAY)")
        expired_auth_requests = await self.execute(
            "DELETE FROM telegram_auth_requests "
            "WHERE expires_at<DATE_SUB(NOW(),INTERVAL 1 DAY)"
        )
        expired_bot_flows = await self.execute(
            "DELETE FROM telegram_bot_sessions WHERE expires_at<NOW()"
        )
        expired_service_nonces = await self.execute(
            "DELETE FROM telegram_service_nonces WHERE expires_at<NOW()"
        )
        expired_telegram_rate_limits = await self.execute(
            "DELETE FROM telegram_rate_limits "
            "WHERE blocked_until<NOW() OR (blocked_until IS NULL "
            "AND window_started_at<DATE_SUB(NOW(),INTERVAL 1 DAY))"
        )
        expired_drafts = await self.execute("DELETE FROM quiz_drafts WHERE expires_at<NOW()")
        expired_challenges = await self.execute("DELETE FROM login_challenges WHERE (used_at IS NOT NULL OR expires_at<NOW()) AND created_at<DATE_SUB(NOW(),INTERVAL 7 DAY)")
        old_attempts = await self.execute("DELETE FROM login_attempts WHERE created_at<DATE_SUB(NOW(),INTERVAL 90 DAY)")
        old_sessions = await self.execute("DELETE FROM user_sessions WHERE (revoked_at IS NOT NULL OR last_activity_at<DATE_SUB(NOW(),INTERVAL 90 DAY)) AND created_at<DATE_SUB(NOW(),INTERVAL 90 DAY)")
        sent_queue = await self.execute("DELETE FROM notification_queue WHERE status='sent' AND sent_at<DATE_SUB(NOW(),INTERVAL 90 DAY)")
        completed_inbound = await self.execute(
            "DELETE FROM telegram_inbound_events "
            "WHERE status='completed' AND completed_at<DATE_SUB(NOW(),INTERVAL 30 DAY)"
        )
        failed_inbound = await self.execute(
            "DELETE FROM telegram_inbound_events "
            "WHERE status='failed' AND updated_at<DATE_SUB(NOW(),INTERVAL 180 DAY)"
        )
        technical_audit = await self.execute("DELETE FROM audit_log WHERE result='success' AND created_at<DATE_SUB(NOW(),INTERVAL 180 DAY) AND action IN ('notification.queued','notification.sent','telegram.link_token_created','auth.password_verified','quiz.draft_saved')")
        return {
            "scheduled_notifications": scheduled,
            "overdue_payments": overdue,
            "task_reminders": task_reminders,
            "timeline_reminders": timeline_reminders,
            "lead_reminders": lead_reminders,
            "expired_login_notifications": expired_login_notifications,
            "expired_password_reset_notifications": expired_password_reset_notifications,
            "expired_links": expired_links,
            "expired_auth_requests": expired_auth_requests,
            "expired_bot_flows": expired_bot_flows,
            "expired_service_nonces": expired_service_nonces,
            "expired_telegram_rate_limits": expired_telegram_rate_limits,
            "expired_drafts": expired_drafts,
            "expired_challenges": expired_challenges,
            "old_login_attempts": old_attempts,
            "old_sessions": old_sessions,
            "sent_queue": sent_queue,
            "completed_inbound": completed_inbound,
            "failed_inbound": failed_inbound,
            "technical_audit": technical_audit,
        }
    async def queue_scheduled_notifications(self) -> int:
        rows = await self.fetch_all(
            "SELECT sn.*,o.client_id,o.manager_id,o.organizer_id FROM order_scheduled_notifications sn JOIN orders o ON o.id=sn.order_id WHERE sn.status='scheduled' AND sn.send_at<=NOW() ORDER BY sn.send_at,id LIMIT 200"
        )
        queued = 0
        for row in rows:
            recipient_id = row.get("recipient_id")
            if not recipient_id:
                recipient_id = {
                    "client": row.get("client_id"),
                    "manager": row.get("manager_id"),
                    "organizer": row.get("organizer_id"),
                }.get(row["recipient_type"])
            payload = row["payload_json"]
            if not isinstance(payload, str):
                payload = json.dumps(payload, ensure_ascii=False)
            inserted = await self.execute(
                "INSERT INTO notification_queue "
                "(recipient_type,recipient_id,event_type,payload_json,status,priority,"
                "next_attempt_at,idempotency_key) "
                "VALUES (%s,%s,%s,%s,'pending',80,NOW(),%s) "
                "ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
                (row["recipient_type"], recipient_id, row["event_type"], payload, f"scheduled-{row['id']}"),
            )
            await self.execute(
                "UPDATE order_scheduled_notifications SET status='queued',queued_at=NOW() WHERE id=%s AND status='scheduled'",
                (row["id"],),
            )
            queued += int(inserted > 0)
        return queued
    async def queue_overdue_payments(self) -> int:
        rows = await self.fetch_all(
            "SELECT p.id,p.order_id,p.title,p.amount,o.client_id,o.title order_title FROM order_payments p JOIN orders o ON o.id=p.order_id WHERE p.due_date<CURDATE() AND p.status IN ('planned','pending') LIMIT 200"
        )
        queued = 0
        for row in rows:
            text = f"Просрочен платёж «{row['title']}» по заказу «{row['order_title']}». Сумма: {row['amount']} руб."
            inserted = await self.execute(
                "INSERT INTO notification_queue "
                "(recipient_type,recipient_id,event_type,payload_json,status,priority,"
                "next_attempt_at,idempotency_key) "
                "VALUES ('user',%s,'payment_overdue',%s,'pending',30,NOW(),%s) "
                "ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
                (row["client_id"], json.dumps({"text": text, "order_id": row["order_id"], "payment_id": row["id"]}, ensure_ascii=False), f"payment-overdue-{row['id']}"),
            )
            await self.execute("UPDATE order_payments SET status='overdue' WHERE id=%s AND status IN ('planned','pending')", (row["id"],))
            queued += int(inserted > 0)
        return queued
    async def queue_task_reminders(self) -> int:
        rows = await self.fetch_all(
            "SELECT t.id,t.order_id,t.title,t.due_date,t.assigned_to,o.title order_title FROM order_tasks t JOIN orders o ON o.id=t.order_id WHERE t.assigned_to IS NOT NULL AND t.status IN ('todo','in_progress') AND t.due_date IS NOT NULL AND t.due_date<=DATE_ADD(CURDATE(),INTERVAL 1 DAY) LIMIT 300"
        )
        queued = 0
        for row in rows:
            text = f"Приближается срок задачи «{row['title']}» по заказу «{row['order_title']}»: {row['due_date']}."
            inserted = await self.execute(
                "INSERT INTO notification_queue "
                "(recipient_type,recipient_id,event_type,payload_json,status,priority,"
                "next_attempt_at,idempotency_key) "
                "VALUES ('user',%s,'task_deadline',%s,'pending',50,NOW(),%s) "
                "ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
                (row["assigned_to"], json.dumps({"text": text, "order_id": row["order_id"], "task_id": row["id"]}, ensure_ascii=False), f"task-deadline-{row['id']}-{row['due_date']}"),
            )
            queued += int(inserted > 0)
        return queued
    async def queue_timeline_reminders(self) -> int:
        rows = await self.fetch_all(
            "SELECT ti.id,ti.order_id,ti.title,ti.starts_at,ti.ends_at,ti.status,"
            "ti.responsible_id,o.title order_title,(ti.ends_at<NOW()) is_delayed "
            "FROM order_timeline_items ti JOIN orders o ON o.id=ti.order_id "
            "WHERE ti.responsible_id IS NOT NULL "
            "AND ti.status NOT IN ('completed','cancelled') "
            "AND (ti.starts_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 24 HOUR) "
            "OR ti.ends_at<NOW()) LIMIT 300"
        )
        queued = 0
        for row in rows:
            delayed = bool(row["is_delayed"])
            event_type = "timeline_delayed" if delayed else "timeline_due"
            text = ("Задерживается" if delayed else "Скоро начнётся") + f" этап «{row['title']}» по заказу «{row['order_title']}»."
            key_date = row["ends_at" if delayed else "starts_at"].strftime("%Y%m%d%H")
            inserted = await self.execute(
                "INSERT INTO notification_queue "
                "(recipient_type,recipient_id,event_type,payload_json,status,priority,"
                "next_attempt_at,idempotency_key) "
                "VALUES ('user',%s,%s,%s,'pending',45,NOW(),%s) "
                "ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
                (row["responsible_id"], event_type, json.dumps({"text": text, "order_id": row["order_id"], "timeline_item_id": row["id"]}, ensure_ascii=False), f"{event_type}-{row['id']}-{key_date}"),
            )
            if delayed and row["status"] not in ("delayed", "completed", "cancelled"):
                await self.execute("UPDATE order_timeline_items SET status='delayed' WHERE id=%s AND status NOT IN ('completed','cancelled')", (row["id"],))
            queued += int(inserted > 0)
        return queued
    async def queue_lead_reminders(self) -> int:
        rows = await self.fetch_all(
            "SELECT id,client_name,manager_id,reminder_at FROM leads WHERE manager_id IS NOT NULL AND reminder_at IS NOT NULL AND reminder_at<=NOW() AND status NOT IN ('completed','rejected','archived') LIMIT 200"
        )
        queued = 0
        for row in rows:
            text = f"Напоминание по заявке #{row['id']}: {row['client_name']}. Откройте карточку в CRM."
            key_date = row["reminder_at"].strftime("%Y%m%d%H%M")
            inserted = await self.execute(
                "INSERT INTO notification_queue "
                "(recipient_type,recipient_id,event_type,payload_json,status,priority,"
                "next_attempt_at,idempotency_key) "
                "VALUES ('user',%s,'lead_followup_due',%s,'pending',35,NOW(),%s) "
                "ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)",
                (row["manager_id"], json.dumps({"text": text, "lead_id": row["id"]}, ensure_ascii=False), f"lead-reminder-{row['id']}-{key_date}"),
            )
            queued += int(inserted > 0)
        return queued
    @staticmethod
    def utc_now() -> str:
        return datetime.now(timezone.utc).isoformat()
