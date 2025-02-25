<?php

namespace App\Models;

use CodeIgniter\Model;

class MentorshipTypesModel extends Model {
    protected $table = 'mentorship_types';

    protected $primaryKey = 'id';

    protected $allowedFields = ['id','title'];
    protected $useTimestamps = false;
}

