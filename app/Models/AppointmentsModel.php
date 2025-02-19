<?php

namespace App\Models;

use CodeIgniter\Model;

class AppointmentsModel extends Model {
    protected $table = 'appointments';

    protected $primaryKey = 'id';

    protected $allowedFields = ['id','location_id', 'citizen_id','date','time', 'status'];
    protected $useTimestamps = true;
}

