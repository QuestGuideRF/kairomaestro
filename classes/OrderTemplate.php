<?php
class OrderTemplate {
    private const DEFAULT_START_TIME = '10:00';
    private const DEFAULT_END_TIME = '15:00';
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    public function save($templateId, $userId, $data) {
        return (new OrderTemplateWriter($this->db))->save($templateId, $userId, $data);
    }
    public function createPreset($type, $userId) {
        $presets = [
            'wedding' => ['Свадьба под ключ', 'Свадьба', $this->weddingPreset()],
            'decor' => ['Декор и флористика', 'Оформление события', $this->decorPreset()],
            'chamber' => ['Камерное событие', 'Камерное событие', $this->chamberPreset()],
            'corporate' => ['Корпоратив', 'Корпоратив', ['tasks'=>[['title'=>'Согласовать брендбук','days_before'=>30],['title'=>'Подтвердить площадку','days_before'=>21]],'timeline'=>[['title'=>'Монтаж','start_time'=>self::DEFAULT_START_TIME,'end_time'=>self::DEFAULT_END_TIME],['title'=>'Мероприятие','start_time'=>'18:00','end_time'=>'23:00']]]],
            'birthday' => ['День рождения', 'День рождения', ['tasks'=>[['title'=>'Утвердить оформление','days_before'=>21]],'timeline'=>[['title'=>'Монтаж декора','start_time'=>self::DEFAULT_START_TIME,'end_time'=>self::DEFAULT_END_TIME]]]],
            'graduation' => ['Выпускной', 'Выпускной', ['tasks'=>[['title'=>'Замер площадки','days_before'=>45],['title'=>'Согласовать фотозону','days_before'=>30]]]],
            'photozone' => ['Фотозона', 'Фотозона', ['tasks'=>[['title'=>'Утвердить эскиз','days_before'=>14]],'timeline'=>[['title'=>'Монтаж фотозоны','start_time'=>'09:00','end_time'=>'13:00']]]],
            'restaurant' => ['Оформление ресторана', 'Оформление ресторана', ['tasks'=>[['title'=>'Провести замер','days_before'=>30],['title'=>'Согласовать рассадку','days_before'=>14]]]],
            'custom' => ['Индивидуальный проект', 'Другое', ['tasks'=>[['title'=>'Провести бриф','days_before'=>60]]]],
        ];
        if (!isset($presets[$type])) {
            return ['success' => false, 'error' => 'Неизвестный шаблон'];
        }
        [$name, $eventType, $payload] = $presets[$type];
        return $this->save(null, $userId, [
            'template_name' => $name,
            'description' => 'Базовый шаблон. Отредактируйте этапы под процессы агентства.',
            'event_type' => $eventType,
            'template_payload' => $payload,
            'is_active' => 1,
        ]);
    }
    private function weddingPreset() {
        return [
            'tasks' => [
                ['title'=>'Провести установочную встречу','days_before'=>180,'visibility'=>'client'],
                ['title'=>'Подтвердить площадку и условия доступа','days_before'=>150],
                ['title'=>'Собрать референсы и пожелания','days_before'=>140,'visibility'=>'client'],
                ['title'=>'Подготовить концепцию события','days_before'=>120,'visibility'=>'client'],
                ['title'=>'Согласовать смету','days_before'=>100,'visibility'=>'client'],
                ['title'=>'Подписать договор','days_before'=>90,'visibility'=>'client'],
                ['title'=>'Сформировать команду проекта','days_before'=>75],
                ['title'=>'Утвердить флористику и материалы','days_before'=>60,'visibility'=>'client'],
                ['title'=>'Провести технический выезд','days_before'=>45],
                ['title'=>'Подтвердить логистику и монтаж','days_before'=>21],
                ['title'=>'Провести финальное согласование','days_before'=>7,'visibility'=>'client'],
                ['title'=>'Проверить готовность команды','days_before'=>2],
                ['title'=>'Закрыть документы и итоги проекта','days_before'=>0],
            ],
            'checklists' => [
                ['title'=>'Договор с площадкой подтверждён'],
                ['title'=>'План расстановки согласован'],
                ['title'=>'Палитра и состав флористики утверждены'],
                ['title'=>'Время монтажа и демонтажа подтверждено'],
                ['title'=>'Команда подтвердила участие'],
                ['title'=>'Контакты подрядчиков собраны'],
                ['title'=>'План Б на случай погоды согласован'],
                ['title'=>'Финальный тайминг отправлен команде'],
            ],
            'roles' => [
                ['role'=>'florist','quantity'=>2,'notes'=>'Подготовка и сборка флористики'],
                ['role'=>'installer','quantity'=>3,'notes'=>'Монтаж и демонтаж декора'],
                ['role'=>'helper','quantity'=>2,'notes'=>'Помощь на площадке'],
                ['role'=>'courier','quantity'=>1,'notes'=>'Доставка материалов'],
            ],
            'timeline' => [
                ['title'=>'Доставка материалов','start_time'=>'07:00','end_time'=>'08:00','priority'=>'high'],
                ['title'=>'Монтаж декора','start_time'=>'08:00','end_time'=>'13:00','priority'=>'critical'],
                ['title'=>'Флористическое оформление','start_time'=>'09:00','end_time'=>'14:00','priority'=>'high'],
                ['title'=>'Приёмка площадки','start_time'=>'14:00','end_time'=>'14:30','priority'=>'critical'],
                ['title'=>'Церемония','start_time'=>'16:00','end_time'=>'17:00','priority'=>'high'],
                ['title'=>'Контроль оформления во время события','start_time'=>'17:00','end_time'=>'22:30'],
                ['title'=>'Демонтаж','start_time'=>'23:00','end_time'=>'23:59','priority'=>'high'],
            ],
            'expenses' => [
                ['category'=>'flowers','title'=>'Цветы и зелень','amount'=>0],
                ['category'=>'decor','title'=>'Изготовление декора','amount'=>0],
                ['category'=>'rent','title'=>'Аренда конструкций и текстиля','amount'=>0],
                ['category'=>'transport','title'=>'Логистика','amount'=>0],
                ['category'=>'installation','title'=>'Монтаж','amount'=>0],
                ['category'=>'dismantling','title'=>'Демонтаж','amount'=>0],
            ],
            'payments' => [
                ['title'=>'Предоплата','amount'=>0,'days_before'=>90],
                ['title'=>'Промежуточный платёж','amount'=>0,'days_before'=>30],
                ['title'=>'Финальный платёж','amount'=>0,'days_before'=>7],
            ],
            'documents' => [
                ['document_type'=>'contract','title'=>'Договор на организацию события','body_html'=>'<h1>{{order_title}}</h1><p>Клиент: <strong>{{client_name}}</strong></p><p>Дата события: {{event_date}}</p><p>Условия и состав работ заполняются организатором.</p>'],
                ['document_type'=>'estimate_appendix','title'=>'Состав работ и смета','body_html'=>'<h1>Состав работ по проекту {{order_title}}</h1><p>Площадка: {{venue}}</p><p>Перечень работ и стоимость заполняются организатором.</p>'],
            ],
            'notifications' => [
                ['event_type'=>'wedding_final_check','recipient_type'=>'organizer','days_before'=>7,'send_time'=>'10:00','text'=>'Провести финальную проверку проекта и согласований.'],
                ['event_type'=>'wedding_client_reminder','recipient_type'=>'client','days_before'=>3,'send_time'=>'12:00','text'=>'Финальная подготовка завершена. Проверьте сообщения и документы в кабинете.'],
                ['event_type'=>'wedding_team_check','recipient_type'=>'organizer','days_before'=>1,'send_time'=>'10:00','text'=>'Подтвердить готовность команды и логистику на завтра.'],
            ],
        ];
    }
    private function decorPreset() {
        return [
            'tasks' => [
                ['title'=>'Получить план и фотографии площадки','days_before'=>75],
                ['title'=>'Провести замер площадки','days_before'=>60],
                ['title'=>'Подготовить концепцию декора','days_before'=>50,'visibility'=>'client'],
                ['title'=>'Согласовать палитру и флористику','days_before'=>40,'visibility'=>'client'],
                ['title'=>'Утвердить смету и перечень аренды','days_before'=>35,'visibility'=>'client'],
                ['title'=>'Заказать цветы и материалы','days_before'=>21],
                ['title'=>'Подтвердить монтажную команду','days_before'=>14],
                ['title'=>'Проверить логистику','days_before'=>3],
            ],
            'checklists' => [
                ['title'=>'Размеры и точки крепления проверены'],
                ['title'=>'Ограничения площадки получены'],
                ['title'=>'Список аренды подтверждён'],
                ['title'=>'Замены по сезонным цветам согласованы'],
                ['title'=>'Время демонтажа подтверждено'],
            ],
            'roles' => [
                ['role'=>'florist','quantity'=>2,'notes'=>'Сборка композиций'],
                ['role'=>'installer','quantity'=>2,'notes'=>'Монтаж конструкций'],
                ['role'=>'helper','quantity'=>1,'notes'=>'Помощь на площадке'],
                ['role'=>'courier','quantity'=>1,'notes'=>'Доставка'],
            ],
            'timeline' => [
                ['title'=>'Доставка','start_time'=>'08:00','end_time'=>'09:00','priority'=>'high'],
                ['title'=>'Монтаж конструкций','start_time'=>'09:00','end_time'=>'12:00','priority'=>'critical'],
                ['title'=>'Флористика','start_time'=>'10:00','end_time'=>'13:30','priority'=>'high'],
                ['title'=>'Финальная приёмка','start_time'=>'13:30','end_time'=>'14:00','priority'=>'critical'],
                ['title'=>'Демонтаж','start_time'=>'23:00','end_time'=>'23:59','priority'=>'high'],
            ],
            'expenses' => [
                ['category'=>'flowers','title'=>'Цветы','amount'=>0],
                ['category'=>'decor','title'=>'Декор и расходные материалы','amount'=>0],
                ['category'=>'rent','title'=>'Аренда','amount'=>0],
                ['category'=>'transport','title'=>'Доставка','amount'=>0],
            ],
            'payments' => [
                ['title'=>'Предоплата за материалы','amount'=>0,'days_before'=>35],
                ['title'=>'Финальный платёж','amount'=>0,'days_before'=>5],
            ],
            'documents' => [],
            'notifications' => [
                ['event_type'=>'decor_material_check','recipient_type'=>'organizer','days_before'=>7,'send_time'=>'10:00','text'=>'Проверить наличие материалов, цветов и аренды.'],
            ],
        ];
    }
    private function chamberPreset() {
        return [
            'tasks' => [
                ['title'=>'Уточнить формат и число гостей','days_before'=>45,'visibility'=>'client'],
                ['title'=>'Согласовать стилистику стола','days_before'=>35,'visibility'=>'client'],
                ['title'=>'Подтвердить меню и рассадку','days_before'=>25],
                ['title'=>'Утвердить цветы и детали сервировки','days_before'=>18,'visibility'=>'client'],
                ['title'=>'Подтвердить доставку и монтаж','days_before'=>7],
                ['title'=>'Финальный созвон','days_before'=>2,'visibility'=>'client'],
            ],
            'checklists' => [
                ['title'=>'Число посадочных мест подтверждено'],
                ['title'=>'План стола согласован'],
                ['title'=>'Свечи и текстиль подтверждены площадкой'],
                ['title'=>'Доставка согласована'],
            ],
            'roles' => [
                ['role'=>'florist','quantity'=>1,'notes'=>'Камерная флористика'],
                ['role'=>'helper','quantity'=>1,'notes'=>'Сервировка и сборка'],
                ['role'=>'courier','quantity'=>1,'notes'=>'Доставка материалов'],
            ],
            'timeline' => [
                ['title'=>'Доставка','start_time'=>'12:00','end_time'=>'12:30'],
                ['title'=>'Сервировка и декор','start_time'=>'12:30','end_time'=>'15:00','priority'=>'high'],
                ['title'=>'Флористика','start_time'=>'13:00','end_time'=>'15:00','priority'=>'high'],
                ['title'=>'Приёмка','start_time'=>'15:00','end_time'=>'15:30','priority'=>'critical'],
                ['title'=>'Сбор декора','start_time'=>'22:00','end_time'=>'23:00'],
            ],
            'expenses' => [
                ['category'=>'flowers','title'=>'Цветы','amount'=>0],
                ['category'=>'rent','title'=>'Посуда, текстиль и свечи','amount'=>0],
                ['category'=>'transport','title'=>'Доставка','amount'=>0],
            ],
            'payments' => [
                ['title'=>'Предоплата','amount'=>0,'days_before'=>25],
                ['title'=>'Окончательный расчёт','amount'=>0,'days_before'=>3],
            ],
            'documents' => [],
            'notifications' => [
                ['event_type'=>'chamber_final_check','recipient_type'=>'organizer','days_before'=>2,'send_time'=>'11:00','text'=>'Проверить финальное количество гостей и сервировку.'],
            ],
        ];
    }
    public function apply($templateId, $orderId, $userId) {
        return (new OrderTemplateApplier($this->db))->apply($templateId, $orderId, $userId);
    }
    public function all($activeOnly = true) {
        $sql = 'SELECT et.*,u.first_name,u.last_name FROM event_templates et JOIN users u ON u.id=et.organizer_id';
        if ($activeOnly) {
            $sql .= ' WHERE et.is_active=1';
        }
        $sql .= ' ORDER BY et.template_name';
        return $this->db->query($sql)->fetchAll();
    }
    public function getById($id) {
        $stmt = $this->db->prepare("SELECT * FROM event_templates WHERE id=?");
        $stmt->execute([(int)$id]);
        return $stmt->fetch();
    }
}
