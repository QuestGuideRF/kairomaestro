<?php
trait OrderOrganizerSupport {
    public function addTeamMember($orderId, $workerId, $userId, $data) {
        return (new OrderTeamService($this->db))->assign($orderId, $workerId, $userId, $data);
    }
    public function setTeamClientVisibility($teamMemberId, $userId, $visible) {
        return (new OrderTeamService($this->db))->setClientVisibility(
            $teamMemberId,
            $userId,
            $visible
        );
    }
}
