<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

class Host extends BaseController
{
    protected $db;

    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
        $this->db = Database::connect();
    }

    // GET ALL HOSTS
    public function getAllHosts()
    {
        $hosts = $this->db->table('hosts')->get()->getResult();

        $baseURL = base_url('public/uploads/host_images/'); // your image folder

        foreach ($hosts as $host) {
            $host->host_image = $host->host_image
                ? $baseURL . $host->host_image
                : null;
        }

        return $this->response->setJSON([
            'status' => 200,
            'message' => 'Host list fetched successfully',
            'data' => $hosts
        ]);
    }

    // GET SINGLE HOST BY ID
    public function getHostById($host_id)
    {
        if (empty($host_id)) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_BAD_REQUEST)
                ->setJSON([
                    'status' => false,
                    'message' => 'host_id is required'
                ]);
        }

        $host = $this->db->table('hosts')->where('host_id', $host_id)->get()->getRow();

        if (!$host) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Host not found'
            ]);
        }

        $baseURL = base_url('public/uploads/host_images/');

        $host->host_image = $host->host_image
            ? $baseURL . $host->host_image
            : null;

        return $this->response->setJSON([
            'status' => 200,
            'message' => 'Host details fetched successfully',
            'data' => $host
        ]);
    }

}
