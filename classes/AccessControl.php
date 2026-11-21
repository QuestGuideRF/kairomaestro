<?php
class AccessControl {
    private const ROLE_PERMISSIONS = [
        'admin' => ['*'],
        'manager' => [
            'manage_leads', 'view_orders', 'create_orders',
        ],
        'organizer' => [
            'view_orders', 'manage_orders', 'view_finance', 'manage_finance',
            'manage_documents', 'manage_templates', 'assign_workers',
            'override_calendar_conflict', 'view_calendar', 'manage_calendar',
            'manage_timeline', 'manage_concepts',
        ],
        'client' => ['view_own_orders', 'confirm_documents', 'approve_concepts', 'submit_reviews'],
        'worker' => ['view_assigned_work', 'view_calendar'],
        'florist' => ['view_assigned_work', 'view_calendar'],
        'courier' => ['view_assigned_work', 'view_calendar'],
        'installer' => ['view_assigned_work', 'view_calendar'],
        'helper' => ['view_assigned_work', 'view_calendar'],
    ];
    public static function allows($permission, $role = null) {
        $role = $role ?? currentRole();
        $permissions = self::ROLE_PERMISSIONS[$role] ?? [];
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
    public static function requirePermission($permission) {
        requireLogin();
        if (!self::allows($permission)) {
            AuditLog::record('permission.denied', 'permission', null, null, ['permission' => $permission], [
                'result' => 'denied',
            ]);
            http_response_code(403);
            die('Недостаточно прав для выполнения действия.');
        }
    }
}
