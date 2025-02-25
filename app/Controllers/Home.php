<?php

namespace App\Controllers;

use App\Models\IssuesModel;
use App\Models\IssueCategoriesModel;
use App\Models\AppointmentsModel;
use App\Models\UsersModel;
use App\Models\MentorshipTypesModel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use ArrayAccess;
use \Firebase\JWT\JWT;
use Firebase\JWT\Key;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

class Home extends BaseController
{
    // private Redis $redis;
    private $accessData;

    public function index()
    {
        return $this->response->setStatusCode(200)->setJSON(["message" => "api is well configured"]);
    }
    public function _secure($token = '')
    {
        $this->appendHeader();
        $token = $token ? $token : $this->request->getHeader('Authorization')->getValue();
        $key = getenv('JWT_SECRET');

        try {
            if (is_null($token) || empty($token)) {
                return $this->response->setStatusCode(401)->setJSON(['error' => 'Unauthorized', "message" => "token is empty"]);
            }
            // Decode the token
            $this->accessData = JWT::decode($token, new Key($key, 'HS256'));

            if (!$this->accessData) {
                // Token is invalid or expired
                return $this->response->setStatusCode(401)->setJSON(['error' => 'Unauthorized', "message" => "Token is invalid or expired"]);
            }
        } catch (\Exception $e) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'Unauthorized', "message" => $e->getMessage()]);
        }
    }
    public function login()
    {
        $this->appendHeader();
        $model = new UsersModel();
        $input = $this->request->getJSON();

        try {
            $email = $input->email;
            $password = $input->password;
            $key = 'email';
            $result = $model->checkUser($email, $key);
            if ($result != null) {
                if (password_verify($password, $result->password)) {
                    if ($result->status == 1) {
                        $payload = array(
                            "iat" => time(),
                            "name" => $result->names,
                            'email' => $result->email,
                            "uid" => $result->id,
                            "lct" => $result->location,
                            "dnm" => $result->title ?? '',
                            "typ" => $result->type
                        );
                        $key = getenv('JWT_SECRET');
                        $token = JWT::encode($payload, $key, 'HS256');

                        $data = array(
                            "uid" => $result->id,
                            "name" => $result->names,
                            'email' => $result->email,
                            "token" => $token,
                            "location" => $result->location,
                            "district" => $result->title ?? '',
                            "type" => $result->type
                        );
                        return $this->response->setStatusCode(200)->setJSON($data);
                    } else {
                        return $this->response->setStatusCode(400)->setJSON(array("error" => lang('accountLocked'), "message" => "your account is locked"));
                    }
                } else {
                    return $this->response->setStatusCode(403)->setJSON(array("error" => lang('invalidLogin'), "message" => "username or Password is not correct"));
                }
            } else {
                return $this->response->setStatusCode(403)->setJSON(["error" =>
                lang('invalidLogin'), "message" => "username or Password is not correct"]);
            }
        } catch (\Exception $e) {
            return $this->response->setStatusCode(403)->setJSON(array("error" => lang('invalidLogin'), "message" => lang('app.provideRequiredData') . $e->getMessage()));
        }
    }
    function getIssues($output = 0, $limit = '', $token = '')
    {
        empty($token) ? $this->_secure() : $this->_secure($token);
        // $this->appendHeader();
        $mdl = new IssuesModel();
        $resultBuilder = $mdl->select("issues.id, issues.title, issues.details, ic.title as category, u.names as citizen, issues.status, issues.created_at")
            ->join('issue_categories ic', 'ic.id = issues.category_id')
            ->join('users u', 'u.id = issues.user_id')
            ->where('issues.location_id', $this->accessData->lct);
        
        if($this->accessData->typ == '3') {
            $resultBuilder->where('issues.user_id', $this->accessData->uid);
        }
        if (!empty($limit)) {
            $result = $resultBuilder->limit($limit);
        }
        $result = $resultBuilder->get()->getResultArray();
        if ($output != 0) {
            try {
                $spreadsheet = new Spreadsheet();
                $worksheet = $spreadsheet->getActiveSheet();
                $styleArray = [
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_HAIR,
                            'color' => ['argb' => 'FFFFFFFF'],
                            'size' => $spreadsheet->getDefaultStyle()->getFont()->setSize(14)
                        ]
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['argb' => 'FF058e8c'],
                    ],
                    'font' => [
                        'bold' => true,
                        'color' => ['argb' => 'FFFFFFFF'],
                    ],
                    'alignment' => [
                        'vertical' => Alignment::VERTICAL_CENTER
                    ]
                ];
                $worksheet->getStyle('A3:F3')->applyFromArray($styleArray);
                $worksheet->getCell('A3')->setValue('#');
                $worksheet->getCell('B3')->setValue('Names');
                $worksheet->getCell('C3')->setValue('Success factor ID');
                $worksheet->getCell('D3')->setValue('District');
                $worksheet->getCell('E3')->setValue('Site');
                $worksheet->getCell('F3')->setValue('Date');

                $i = 4;
                foreach ($result as $res) {


                    $worksheet->getCell('A' . $i)->setValue($res['id']);
                    $worksheet->getCell('B' . $i)->setValue($res['names']);
                    $worksheet->getCell('C' . $i)->setValue($res['sfid']);
                    $worksheet->getCell('D' . $i)->setValue($res['district']);
                    $worksheet->getCell('E' . $i)->setValue($res['sector']);
                    $worksheet->getCell('F' . $i)->setValue($res['event_date']);

                    $worksheet->getRowDimension($i)->setRowHeight(20);

                    $i++;
                }
                $worksheet->setTitle("List of Attendiese");
                $worksheet->getTabColor()->setARGB('FF058e8c');
                $writer = IOFactory::createWriter($spreadsheet, 'Xls');
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Access-Control-Expose-Headers: Content-Disposition');
                header('Content-Disposition: attachment; filename="List of Attendiese - ' . date('Y-m-d') . '.xls"');
                $writer->save("php://output");
            } catch (\Exception $e) {
                return $this->response->setStatusCode(500)->setJSON(["message" => $e->getMessage()]);
            }
        }
        if(empty($result)) {
            return $this->response->setStatusCode(404)->setJSON(["message" => "No data found"]);
        }
        return $this->response->setJSON($result);
    }
    //get issue categories
    public function getIssueCategories()
    {
        $this->_secure();
        $mdl = new IssueCategoriesModel();
        $result = $mdl->findAll();
        return $this->response->setJSON($result);
    }
    //create issue
    public function createIssue()
    {
        $this->_secure();
        $mdl = new IssuesModel();
        $input = $this->request->getJSON();
        $data = [
            'title' => $input->title,
            'details' => $input->details,
            'category_id' => $input->category_id,
            'user_id' => $this->accessData->uid,
            'location_id' => $this->accessData->lct,
            'status' => 0,
            'operator' => $this->accessData->uid
        ];
        $mdl->save($data);
        return $this->response->setJSON(['message' => 'Issue created successfully']);
    }
    //update issue
    public function updateIssue($id)
    {
        $this->_secure();
        $mdl = new IssuesModel();
        $input = $this->request->getJSON();
        $data = [
            'title' => $input->title,
            'details' => $input->details,
            'category_id' => $input->category_id,
            'status' => $input->status,
            'operator' => $this->accessData->uid
        ];
        $mdl->update($id, $data);
        return $this->response->setJSON(['message' => 'Issue updated successfully']);
    }
    //delete issue
    public function deleteIssue($id)
    {
        $this->_secure();
        $mdl = new IssuesModel();
        $mdl->delete($id);
        return $this->response->setJSON(['message' => 'Issue deleted successfully']);
    }
    //get issue by id
    public function getIssue($id)
    {
        $this->_secure();
        $mdl = new IssuesModel();
        $result = $mdl->find($id);
        return $this->response->setJSON($result);
    }
    //get appointments 
    public function getAppointments( $limit = '')
    {
        $this->_secure();
        $mdl = new AppointmentsModel();
        $resultBuilder = $mdl->select("appointments.id, appointments.location_id,COALESCE(date,'-') as date,COALESCE(time,'-') as time, appointments.citizen_id, appointments.status, appointments.created_at, u.names as citizen")
            ->join('users u', 'u.id = appointments.citizen_id')
            ->where('appointments.location_id', $this->accessData->lct);
        
        if($this->accessData->typ == '3') {
            $resultBuilder->where('appointments.citizen_id', $this->accessData->uid);
        }
        if (!empty($limit)) {
            $result = $resultBuilder->limit($limit);
        }
        $result = $resultBuilder->get()->getResultArray();
       return $this->response->setJSON($result);
    }
    //get appointment by id
    public function getAppointment($id)
    {
        $this->_secure();
        $mdl = new AppointmentsModel();
        $result = $mdl->find($id);
        return $this->response->setJSON($result);
    }
    public function getMentorshipTypes()
    {
        $this->_secure();
        $mdl = new MentorshipTypesModel();
        $result = $mdl->findAll();
        return $this->response->setJSON($result);
    }
    //create appointment
    public function createAppointment()
    {
        $this->_secure();
        $mdl = new AppointmentsModel();
        $data = [
            'location_id' => $this->accessData->lct,
            'citizen_id' => $this->accessData->uid,
            'status' => 0
        ];
        $mdl->save($data);
        return $this->response->setJSON(['message' => 'Appointment created successfully']);
    }
    //update appointment
    public function updateAppointment()
    {
        $this->_secure();
        $mdl = new AppointmentsModel();
        $input = $this->request->getJSON();
        $data = [
            'status' => $input->status
        ];
        if($input->status == 1) {
            $data['date'] = $input->date;
            $data['time'] = $input->time;
        }
        $mdl->update($input->id, $data);
        return $this->response->setJSON(['message' => 'Appointment updated successfully']);
    }

    //delete appointment
    public function deleteAppointment($id)
    {
        $this->_secure();
        $mdl = new AppointmentsModel();
        $mdl->delete($id);
        return $this->response->setJSON(['message' => 'Appointment deleted successfully']);
    }
    //get all users
    public function getUsers()
    {
        $this->_secure();
        $mdl = new UsersModel();
        $result = $mdl->findAll();
        return $this->response->setJSON($result);
    }
    //create user
    public function createUser()
    {
        $this->_secure();
        $mdl = new UsersModel();
        $input = $this->request->getJSON();
        $data = [
            'title' => $input->title,
            'names' => $input->names,
            'email' => $input->email,
            'phone' => $input->phone,
            'location' => $input->location,
            'id_number' => $input->id_number,
            'password' => password_hash($input->password, PASSWORD_DEFAULT),
            'type' => $input->type,
            'status' => 1
        ];
        $mdl->save($data);
        return $this->response->setJSON(['message' => 'User created successfully']);
    }
}