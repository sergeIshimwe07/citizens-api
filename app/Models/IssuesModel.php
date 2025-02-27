<?php

namespace App\Models;

use CodeIgniter\Model;

class IssuesModel extends Model {
    protected $table = 'issues';

    protected $primaryKey = 'id';

    protected $allowedFields = ['id','title', 'details', 'category_id', 'user_id', 'status', 'operator'];
    protected $useTimestamps = true;
}

