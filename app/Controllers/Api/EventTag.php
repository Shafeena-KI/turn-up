<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class EventTag extends BaseController
{
    protected $db;

    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

        $this->db = \Config\Database::connect();
    }

    // GET ALL TAGS
    public function getAllTags()
    {
        $tags = $this->db->table('event_tags')->get()->getResult();

        return $this->response->setJSON([
            'status'  => 200,
            'message' => 'Tag list fetched successfully',
            'data'    => $tags
        ]);
    }

    // GET SINGLE TAG BY ID
    public function getTagById($tag_id)
    {
        if (empty($tag_id)) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)
                ->setJSON([
                    'status' => false,
                    'message' => 'tag_id is required'
                ]);
        }

        $tag = $this->db->table('event_tags')->where('tag_id', $tag_id)->get()->getRow();

        if (!$tag) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Tag not found'
            ]);
        }

        return $this->response->setJSON([
            'status'  => true,
            'message' => 'Tag details fetched successfully',
            'data'    => $tag
        ]);
    }
}
