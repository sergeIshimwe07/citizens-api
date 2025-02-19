<?php

namespace App\Models;

use CodeIgniter\Model;

class IssueCategoriesModel extends Model {
    protected $table = 'issue_categories';

    protected $primaryKey = 'id';

    protected $allowedFields = ['id','title'];
    protected $useTimestamps = false;
}

