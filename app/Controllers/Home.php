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
                            "dnm" => $result->title ?? '',
                            "typ" => $result->type,
                            "mnttyp" => $result->mentor_type
                        );
                        $key = getenv('JWT_SECRET');
                        $token = JWT::encode($payload, $key, 'HS256');

                        $redirect = '';
                        if ($result->type == 1) {
                            $redirect = '/users';
                        } else if ($result->type == 2) {
                            $redirect = '/issues';
                        } else if ($result->type == 3) {
                            $redirect = '/appointments';
                        } else if ($result->type == 4) {
                            $redirect = '/issues';
                        }
                        $data = array(
                            "uid" => $result->id,
                            "name" => $result->names,
                            'email' => $result->email,
                            "token" => $token,
                            "type" => $result->type,
                            "mentor_type" => $result->mentor_type,
                            "redirect" => $redirect
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
            ->join('users u', 'u.id = issues.user_id');

        if ($this->accessData->typ == '4') {
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
        if (empty($result)) {
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
    //get change issue status
    public function changeIssueStatus()
    {
        $this->_secure();
        $mdl = new IssuesModel();
        $input = $this->request->getJSON();
        $data = [
            "id" => $input->id,
            'status' => $input->status,
            'operator' => $this->accessData->uid
        ];
        $mdl->save($data);
        //message based on status
        if ($input->status == 1) {
            $message = 'Issue marked active';
        } else if ($input->status == 2) {
            $message = 'Issue marked closed';
        } else if ($input->status == 0) {
            $message = 'Issue Reopened';
        } else {
            $message = 'Issue status updated successfully';
        }
        return $this->response->setJSON(['message' => $message]);
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
    public function getAppointments($limit = '')
    {
        $this->_secure();
        $mdl = new AppointmentsModel();
        $resultBuilder = $mdl->select("appointments.id,COALESCE(date,'-') as date,COALESCE(time,'-') as time,  mt.title as type, appointments.citizen_id, appointments.status, appointments.created_at, u.names as citizen")
            ->join('users u', 'u.id = appointments.citizen_id')
            ->join('mentorship_types mt', 'mt.id = appointments.mentorship_type')
            ->groupBy('appointments.id')
            ->orderBy('appointments.updated_at', 'DESC');

        if ($this->accessData->typ == '4') {
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
        $input = $this->request->getJSON();
        $data = [];
        if (!empty($input->date)) {
            $data['date'] = $input->date;
        }
        if (!empty($input->time)) {
            $data['time'] = $input->time;
        }
        if (!empty($input->status)) {
            $data['status'] = $input->status;
        } else {
            $data['status'] = 0;
        }
        if (!empty($input->type)) {
            $data['mentorship_type'] = $input->type;
        } else {
            $data['mentorship_type'] = $this->accessData->mnttyp;
        }
        if (!empty($input->citizen_id)) {
            $data['citizen_id'] = $input->citizen_id;
        } else {
            $data['citizen_id'] = $this->accessData->uid;
        }
        if (!empty($input->id)) {
            $data['id'] = $input->id;
        }
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
        if (!empty($input->date)) {
            $data['date'] = $input->date;
        }
        if (!empty($input->time)) {
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
    //get all users where status is less than 4 (active)
    public function getUsers()
    {
        $this->_secure();
        $mdl = new UsersModel();
        $resultBuilder = $mdl->select("users.id, users.names, users.email, users.phone, users.id_number, CASE users.type 
            WHEN '1' THEN 'Admin'
            WHEN '2' THEN 'Leader' 
            WHEN '3' THEN 'Mentor'
            ELSE '-'
            END as type, users.status")
            ->where('users.type <', 4);
        
        if ($this->accessData->typ == '2') {
            $resultBuilder->where('user.type', 3);
        } else if($this->accessData->typ == '2') {
            $resultBuilder->where('user.type < ', 4);
        }
        $result = $resultBuilder->get()->getResultArray();
        return $this->response->setJSON($result);
    }
    //create user and generate rangom password then send email to user, there is no title in the user table
    public function createUser()
    {
        $this->_secure();
        $mdl = new UsersModel();
        try {
            $input = $this->request->getJSON();
            $password = $this->random_password(8);
            $data = [
                'names' => $input->names,
                'email' => $input->email,
                'phone' => $input->phone,
                'id_number' => $input->idNumber,
                'type' => $input->type,
                'mentor_type' => $input->type == 3 ? $input->mentorType : 0,
                'status' => 1,
                'password' => password_hash($password, PASSWORD_DEFAULT)
            ];
            $mdl->save($data);
            $data = [
                'password' => $password,
            ];
            $message = view('email', $data);
            $this->sendMail($input->email,  'Account Password', $message,);
            return $this->response->setJSON(['message' => 'User created successfully']);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON(["message" => $e->getMessage()]);
        }
    }
    // Get all users where status is 4 (active)
    public function getActiveResidents()
    {
        $this->_secure();
        $mdl = new UsersModel();
        $result = $mdl->select("users.id, users.names, users.email, users.phone, users.id_number, 'Resident' as type, users.status, COALESCE(s.total_issues, 0) as total_issues, COALESCE(s.resolved_issues, 0) as resolved_issues, COALESCE(ap.total_appointments, 0) as total_appointments, COALESCE(ap.approved_appointments, 0) as approved_appointments")
            ->join('issues i', 'i.user_id = users.id', 'left')
            ->join('appointments a', 'a.citizen_id = users.id', 'left')
            ->join('(Select COUNT(id) as total_issues, COUNT(CASE WHEN status = 1 THEN id END) as resolved_issues, user_id from issues group by user_id) s', 's.user_id = users.id', 'left')
            ->join('(Select COUNT(id) as total_appointments, COUNT(CASE WHEN status = 1 THEN id END) as approved_appointments, citizen_id from appointments group by citizen_id) ap', 'ap.citizen_id = users.id', 'left')
            ->where('users.type', 4)
            ->groupBy('users.id')
            ->get()->getResultArray();
        return $this->response->setJSON($result);
    }

    //get all residents for using in form select
    public function getResidents()
    {
        $this->_secure();
        $mdl = new UsersModel();
        $result = $mdl->select("id, names")
            ->where('status', 1)
            ->where('type', 4)
            ->get()->getResultArray();
        return $this->response->setJSON($result);
    }
    public function changePassword()
	{
		$this->_secure();
		$input = $this->request->getJSON();
		$logger = $this->accessData->uid;
		$formPassword = $input->current;
		$newPassword = $input->newPassword;
		$confirmPassword = $input->confirm;
		if ($newPassword != $confirmPassword) {
			return $this->response->setJSON(array(
				"type" => "error", "message" => "New password is not confirmed"
			));
		}
		$userModel = new UsersModel();
		$password = $userModel->select("password")->where("id", $logger)->get()->getRowArray();
		if (password_verify($formPassword, $password['password'])) {
			$data = array(
				"id" => $logger,
				"password" => password_hash($newPassword, PASSWORD_DEFAULT)
			);
			try {
				$userModel->save($data);
				return $this->response->setJSON([
					"type" => "success", "message" => "Password changed successfully"
				]);
			} catch (\Exception $e) {
				return $this->response->setStatusCode(500)->setJSON(array(
					"error" => "Error occurred", "message" => $e->getMessage()
				));
			}
		} else {
			return $this->response->setJSON(array(
				"tyoe" => "error", "message" => "Invalid Current Password"
			));
		}
	}
    public function createAccount()
    {
        $this->appendHeader();
        $mdl = new UsersModel();
        $input = $this->request->getJSON();

        try {
            $data = [
                'names' => $input->names,
                'phone' => $input->phone,
                'email' => $input->email,
                'id_number' => $input->isibo,
                'type' => 4,
                'status' => 1,
                'password' => password_hash($input->password, PASSWORD_DEFAULT)
            ];
            $id = $mdl->insert($data);

            $payload = array(
                "iat" => time(),
                "name" => $input->names,
                'email' => $input->email,
                "uid" => $id,
                "dnm" => '',
                "typ" => 4,
                "mnttyp" => 0
            );
            $key = getenv('JWT_SECRET');
            $token = JWT::encode($payload, $key, 'HS256');

            $responseData = array(
                "uid" => $id,
                "name" => $input->names,
                'email' => $input->email,
                "token" => $token,
                "type" => 4,
                "mentor_type" => 0,
                "redirect" => '/issues'
            );
            return $this->response->setStatusCode(201)->setJSON($responseData);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON(['message' => $e->getMessage()]);
        }
    }
}
