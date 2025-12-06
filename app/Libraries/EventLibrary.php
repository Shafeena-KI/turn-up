<?php

namespace App\Libraries;
use Config\Database;

class EventLibrary
{
    protected $db;
    public function __construct(){
        
        $this->db = Database::connect();
    }

}