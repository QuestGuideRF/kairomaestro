from __future__ import annotations
import hashlib
import hmac
import json
import secrets
import time
from dataclasses import dataclass
from typing import Any
import aiohttp
class InternalApiError(RuntimeError):
    def __init__(self, message: str, status: int = 0) -> None:
        super().__init__(message)
        self.status = status
    @property
    def retryable(self) -> bool:
        return self.status == 0 or self.status == 429 or self.status >= 500
@dataclass(slots=True)
class InternalApiClient:
    base_url: str
    secret: str
    timeout_seconds: int = 20
    def _headers(self, payload_digest: str) -> dict[str, str]:
        timestamp = str(int(time.time()))
        nonce = secrets.token_urlsafe(32)
        signature_base = f"{timestamp}\n{nonce}\n{payload_digest}".encode()
        signature = hmac.new(
            self.secret.encode(),
            signature_base,
            hashlib.sha256,
        ).hexdigest()
        return {
            "X-Bot-Timestamp": timestamp,
            "X-Bot-Nonce": nonce,
            "X-Bot-Signature": signature,
        }
    async def post_json(self, endpoint: str, payload: dict[str, Any]) -> dict[str, Any]:
        raw = json.dumps(
            payload,
            ensure_ascii=False,
            separators=(",", ":"),
        ).encode("utf-8")
        headers = self._headers(hashlib.sha256(raw).hexdigest())
        headers["Content-Type"] = "application/json; charset=utf-8"
        timeout = aiohttp.ClientTimeout(total=self.timeout_seconds)
        async with (
            aiohttp.ClientSession(timeout=timeout) as session,
            session.post(
                f"{self.base_url.rstrip('/')}/{endpoint.lstrip('/')}",
                data=raw,
                headers=headers,
            ) as response,
        ):
            return await self._decode(response)
    async def post_file(
        self,
        endpoint: str,
        metadata: dict[str, Any],
        filename: str,
        content: bytes,
        content_type: str,
    ) -> dict[str, Any]:
        raw_metadata = json.dumps(
            metadata,
            ensure_ascii=False,
            separators=(",", ":"),
        )
        file_digest = hashlib.sha256(content).hexdigest()
        digest_input = (
            hashlib.sha256(raw_metadata.encode("utf-8")).hexdigest()
            + "\nfile:"
            + file_digest
        )
        headers = self._headers(
            hashlib.sha256(digest_input.encode("utf-8")).hexdigest()
        )
        form = aiohttp.FormData()
        form.add_field("metadata", raw_metadata)
        form.add_field(
            "file",
            content,
            filename=filename,
            content_type=content_type,
        )
        timeout = aiohttp.ClientTimeout(total=max(30, self.timeout_seconds))
        async with (
            aiohttp.ClientSession(timeout=timeout) as session,
            session.post(
                f"{self.base_url.rstrip('/')}/{endpoint.lstrip('/')}",
                data=form,
                headers=headers,
            ) as response,
        ):
            return await self._decode(response)
    @staticmethod
    async def _decode(response: aiohttp.ClientResponse) -> dict[str, Any]:
        try:
            payload = await response.json(content_type=None)
        except (json.JSONDecodeError, aiohttp.ContentTypeError) as error:
            raise InternalApiError(
                f"Внутренний сервис вернул HTTP {response.status}",
                response.status,
            ) from error
        if not isinstance(payload, dict):
            raise InternalApiError(
                "Внутренний сервис вернул некорректный ответ",
                response.status,
            )
        if response.status >= 400 or not payload.get("success"):
            message = str(payload.get("error") or "Внутренний сервис временно недоступен")
            raise InternalApiError(message[:500], response.status)
        return payload
