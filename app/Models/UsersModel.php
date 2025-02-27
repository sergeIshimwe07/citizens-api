<?php

namespace App\Models;

use CodeIgniter\Model;

class UsersModel extends Model {
    protected $table = 'users';

    protected $primaryKey = 'id';

    protected $allowedFields = ['id','title', 'names', 'email','phone', 'id_number', 'password', 'type','mentor_type','mentor_type', 'status'];
    protected $useTimestamps = true;

    public function checkUser($value, $key = "login"){
        $resBuilder = $this->where("(email = '$value' or phone like '%$value')");
        if ($key == 'login'){
        } 
        return $resBuilder->get()->getRow();
    }
}

