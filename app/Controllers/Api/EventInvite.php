<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventInviteModel;
use CodeIgniter\HTTP\ResponseInterface;

class EventInvite extends BaseController
{
    protected $inviteModel;

    public function __construct()
    {
        $this->inviteModel = new EventInviteModel();
    }

    // Create an invite
    public function createInvite()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['event_id']) || empty($data['user_id'])) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'event_id and user_id are required.'
            ])->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST);
        }

        $insertData = [
            'event_id' => $data['event_id'],
            'user_id' => $data['user_id'],
            'invite_type' => $data['invite_type'],
            'status' => 0, 
            'requested_at' => date('Y-m-d H:i:s'),
        ];
        $exists = $this->inviteModel
            ->where(['event_id' => $data['event_id'], 'user_id' => $data['user_id']])
            ->countAllResults();

        if ($exists > 0) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'User already invited for this event.'
            ]);
        }

        $this->inviteModel->insert($insertData);

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Invite created successfully.',
            'data' => $insertData
        ]);
    }

    // Approve or Reject Invite (manual)
    public function updateInviteStatus()
    {
        $data = $this->request->getJSON(true);
        $invite_id = $data['invite_id'] ?? null;
        $status = $data['status'] ?? null;

        if (!$invite_id || !in_array($status, [1, 2])) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'invite_id and valid status (1=approved, 2=rejected) are required.'
            ]);
        }

        $invite = $this->inviteModel->find($invite_id);
        if (!$invite) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Invite not found.'
            ]);
        }

        $updateData = [
            'status' => $status,
            'approved_at' => date('Y-m-d H:i:s')
        ];

        $this->inviteModel->update($invite_id, $updateData);

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Invite updated successfully.'
        ]);
    }

    public function getInvitesByEvent()
    {
        $json = $this->request->getJSON(true);
        $event_id = $json['event_id'] ?? null;

        if (!$event_id) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'event_id is required.'
            ]);
        }

        $invites = $this->inviteModel->getInvitesByEvent($event_id);
        return $this->response->setJSON([
            'status' => true,
            'data' => $invites
        ]);
    }

    public function getInvitesByUser()
    {
        $json = $this->request->getJSON(true);
        $user_id = $json['user_id'] ?? null;
        if (!$user_id) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'user_id is required.'
            ]);
        }

        $invites = $this->inviteModel->getInvitesByUser($user_id);
        return $this->response->setJSON([
            'status' => true,
            'data' => $invites
        ]);
    }
    // Expire old invites automatically (example endpoint)
    public function expireOldInvites()
    {
        // Define the conditions
        $conditions = [
            'status' => 0,
            'requested_at <' => date('Y-m-d H:i:s', strtotime('-7 days'))
        ];

        // Count matching invites first
        $count = $this->inviteModel->where($conditions)->countAllResults();

        // Then update them
        if ($count > 0) {
            $this->inviteModel->where($conditions)->set(['status' => 3])->update();
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => $count . ' pending invite(s) expired successfully.'
        ]);
    }


}
