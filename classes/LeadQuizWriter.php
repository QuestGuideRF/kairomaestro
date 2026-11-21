<?php
class LeadQuizWriter {
    private const DATE_TIME_FORMAT = 'Y-m-d H:i:s';
    private $db;
    public function __construct($db) {
        $this->db = $db;
    }
    public function insert($data, $clientId, $managerId, $submissionHash) {
        $fields = $this->coreFields($data, $clientId, $managerId);
        foreach ($this->optionalFields($data, $submissionHash) as $field => $value) {
            if (dbColumnExists('leads', $field)) {
                $fields[$field] = $value;
            }
        }
        $columns = array_keys($fields);
        $stmt = $this->db->prepare(
            'INSERT INTO leads (`' . implode('`,`', $columns) . '`) VALUES ('
            . implode(',', array_fill(0, count($columns), '?')) . ')'
        );
        $stmt->execute(array_values($fields));
        return (int)$this->db->lastInsertId();
    }
    private function coreFields($data, $clientId, $managerId) {
        $services = array_values((array)($data['services_needed'] ?? []));
        return [
            'client_id' => (int)$clientId,
            'manager_id' => $managerId ? (int)$managerId : null,
            'status' => 'new',
            'client_name' => trim((string)$data['client_name']),
            'phone' => $this->nullableText($data['phone'] ?? ''),
            'email' => $this->nullableText($data['email'] ?? ''),
            'contact_method' => $data['contact_method'] ?? 'site_chat',
            'telegram_username' => $this->nullableText($data['telegram_username'] ?? ''),
            'event_type' => $data['event_type'] ?? 'Свадьба',
            'event_date' => $this->nullableText($data['event_date'] ?? ''),
            'location_city' => $this->nullableText($data['location_city'] ?? ''),
            'location_details' => $this->nullableText($data['location_details'] ?? ''),
            'guests_count' => $this->nullableInteger($data['guests_count'] ?? ''),
            'budget_range' => $this->nullableText($data['budget_range'] ?? ''),
            'style' => $this->nullableText($data['style'] ?? ''),
            'services_needed' => json_encode($services, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'comment' => $this->nullableText($data['comment'] ?? ''),
            'source' => $this->nullableText($data['source'] ?? '') ?? 'quiz',
        ];
    }
    private function optionalFields($data, $submissionHash) {
        $referer = substr((string)($data['referer_url'] ?? ($_SERVER['HTTP_REFERER'] ?? '')), 0, 500);
        return [
            'event_time' => $this->nullableText($data['event_time'] ?? ''),
            'venue' => $this->nullableText($data['venue'] ?? ''),
            'color_palette' => $this->nullableText($data['color_palette'] ?? ''),
            'urgency' => $this->nullableText($data['urgency'] ?? ''),
            'consent_at' => !empty($data['privacy_consent']) ? date(self::DATE_TIME_FORMAT) : null,
            'referer_url' => $this->nullableText($referer),
            'submission_key' => $submissionHash,
            'utm_source' => $this->nullableText($data['utm_source'] ?? ''),
            'utm_medium' => $this->nullableText($data['utm_medium'] ?? ''),
            'utm_campaign' => $this->nullableText($data['utm_campaign'] ?? ''),
            'utm_content' => $this->nullableText($data['utm_content'] ?? ''),
            'utm_term' => $this->nullableText($data['utm_term'] ?? ''),
        ];
    }
    private function nullableText($value) {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
    private function nullableInteger($value) {
        return $value === '' || $value === null ? null : (int)$value;
    }
}
