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
                            'phone' => $result->phone,
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
                            'phone' => $result->phone,
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
    function getIssues($output = 0, $token = '')
    {
        empty($token) ? $this->_secure() : $this->_secure($token);
        // $this->appendHeader();
        $mdl = new IssuesModel();
        $resultBuilder = $mdl->select("issues.id, issues.title, issues.details, ic.title as category,COALESCE(feedback, '-') as feedback, u.names as citizen, issues.status, issues.created_at")
            ->join('issue_categories ic', 'ic.id = issues.category_id')
            ->join('users u', 'u.id = issues.user_id');

        if ($this->accessData->typ == '4') {
            $resultBuilder->where('issues.user_id', $this->accessData->uid);
        }
        $result = $resultBuilder->get()->getResultArray();
        if (empty($result)) {
            return $this->response->setStatusCode(404)->setJSON(["message" => "No data found"]);
        }
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
                $worksheet->getStyle('A3:G3')->applyFromArray($styleArray);
                $worksheet->getCell('A3')->setValue('#');
                $worksheet->getCell('B3')->setValue('Citizen');
                $worksheet->getCell('C3')->setValue('Category');
                $worksheet->getCell('D3')->setValue('Title');
                $worksheet->getCell('E3')->setValue('Status');
                $worksheet->getCell('F3')->setValue('Date');
                $worksheet->getCell('G3')->setValue('Feedback');


                $i = 4;
                foreach ($result as $res) {


                    $worksheet->getCell('A' . $i)->setValue($res['id']);
                    $worksheet->getCell('B' . $i)->setValue($res['citizen']);
                    $worksheet->getCell('C' . $i)->setValue($res['category']);
                    $worksheet->getCell('D' . $i)->setValue($res['title']);
                    $worksheet->getCell('E' . $i)->setValue($res['status']);
                    $worksheet->getCell('F' . $i)->setValue($res['created_at']);
                    $worksheet->getCell('G' . $i)->setValue($res['feedback']);

                    $worksheet->getRowDimension($i)->setRowHeight(20);

                    $i++;
                }
                $worksheet->setTitle("List of Issues");
                $worksheet->getTabColor()->setARGB('FF058e8c');
                $writer = IOFactory::createWriter($spreadsheet, 'Xls');
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Access-Control-Expose-Headers: Content-Disposition');
                header('Content-Disposition: attachment; filename="List of Issues - ' . date('Y-m-d') . '.xls"');
                $writer->save("php://output");
            } catch (\Exception $e) {
                return $this->response->setStatusCode(500)->setJSON(["message" => $e->getMessage()]);
            }
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
        if ($input->feedback !== "") {
            $data['feedback'] = $input->feedback;
        }
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
    public function deleteIssue()
    {
        $this->_secure();
        $mdl = new IssuesModel();
        $input = $this->request->getJSON();
        try {
            $issue = $mdl->find($input->id);
            if (!$issue) {
                return $this->response->setStatusCode(404)->setJSON(['error' => 'Not Found', 'message' => 'Issue not found']);
            }
            if ($issue && $issue['user_id'] != $this->accessData->uid) {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Forbidden', 'message' => 'You are not allowed to delete this issue']);
            } else if ($issue && $issue['status'] != 0) {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Forbidden', 'message' => 'You can\'t delete active or closed issue']);
            }
            $mdl->delete($input->id);
            return $this->response->setJSON(['message' => 'Issue deleted successfully']);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Error occurred', 'message' => $e->getMessage()]);
        }
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
    public function getAppointments($output = 0, $token = '')
    {
        empty($token) ? $this->_secure() : $this->_secure($token);
        $mdl = new AppointmentsModel();
        $resultBuilder = $mdl->select("appointments.id,COALESCE(date,'-') as date,COALESCE(time,'-') as time,COALESCE(feedback,'-') as feedback,  mt.title as type, appointments.citizen_id, appointments.status, appointments.created_at, u.names as citizen")
            ->join('users u', 'u.id = appointments.citizen_id')
            ->join('mentorship_types mt', 'mt.id = appointments.mentorship_type')
            ->groupBy('appointments.id')
            ->orderBy('appointments.updated_at', 'DESC');

        if ($this->accessData->typ == '4') {
            $resultBuilder->where('appointments.citizen_id', $this->accessData->uid);
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
                $worksheet->getStyle('A3:H3')->applyFromArray($styleArray);
                $worksheet->getCell('A3')->setValue('#');
                $worksheet->getCell('B3')->setValue('Citizen');
                $worksheet->getCell('C3')->setValue('Date');
                $worksheet->getCell('D3')->setValue('Time');
                $worksheet->getCell('E3')->setValue('Date');
                $worksheet->getCell('F3')->setValue('Type');
                $worksheet->getCell('G3')->setValue('Feedback');
                $worksheet->getCell('H3')->setValue('Status');

                $i = 4;
                foreach ($result as $res) {


                    $worksheet->getCell('A' . $i)->setValue($res['id']);
                    $worksheet->getCell('B' . $i)->setValue($res['citizen']);
                    $worksheet->getCell('C' . $i)->setValue($res['date']);
                    $worksheet->getCell('D' . $i)->setValue($res['time']);
                    $worksheet->getCell('E' . $i)->setValue($res['created_at']);
                    $worksheet->getCell('F' . $i)->setValue($res['type']);
                    $worksheet->getCell('G' . $i)->setValue($res['feedback']);
                    $worksheet->getCell('H' . $i)->setValue($res['status']);

                    $worksheet->getRowDimension($i)->setRowHeight(20);

                    $i++;
                }
                $worksheet->setTitle("List of Appointments");
                $worksheet->getTabColor()->setARGB('FF058e8c');
                $writer = IOFactory::createWriter($spreadsheet, 'Xls');
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Access-Control-Expose-Headers: Content-Disposition');
                header('Content-Disposition: attachment; filename="List of Appointments - ' . date('Y-m-d') . '.xls"');
                $writer->save("php://output");
            } catch (\Exception $e) {
                return $this->response->setStatusCode(500)->setJSON(["message" => $e->getMessage()]);
            }
        }
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
        if (!empty($input->feedback)) {
            $data['feedback'] = $input->feedback;
        }
        $citizenId = $data['citizen_id'];
        //get user email of citizen
        $userModel = new UsersModel();
        $user = $userModel->find($citizenId);
        $email = $user['email'];
        $mdl->save($data);
        $statusMesasge = "";
        //case statement to send email based on status
        if ($input->status !== 0) {
            if ($input->status == 1 && !empty($input->citizen_id)) {
                $statusMesasge = 'Your appointment on VIMMS has been approved, login into your account to check dates and time';
            } elseif ($input->status == 1 && empty($input->citizen_id)) {
                $statusMesasge = 'You have been invited to an appointment on VIMMS, login into your account to check dates and time';
            } elseif ($input->status == 2) {
                $statusMesasge = 'Your appointment has been marked as complete and closed';
            } elseif ($input->status == 3) {
                $statusMesasge = 'Your appointment has been marked as expired and closed';
            } elseif ($input->status == 4) {
                $statusMesasge = 'Your appointment has been marked as missed and closed';
            }
            $data = [
                'message' => $statusMesasge
            ];
            $message = view('appointment', $data);
        }
        $this->sendMail($email,  'Appointment update', $message,);
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
        

        $citizenId = $input->citizen_id;
        //get user email of citizen
        $userModel = new UsersModel();
        $user = $userModel->find($citizenId);
        $email = $user['email'];
        $statusMesasge = "";
        //case statement to send email based on status
        switch ($input->status) {
            case 1:
                $statusMesasge = 'Your appointment on VIMMS has been approved, login into your account to check dates and time';
                break;
            case 2:
                $statusMesasge = 'Your appointment has been marked as complete and closed';
                break;
            case 3:
                $statusMesasge = 'Your appointment has been marked as expired and closed';
                break;
            case 4:
                $statusMesasge = 'Your appointment has been marked as missed and closed';
                break;
            default:
                $statusMesasge = 'updated';
                break;
        }
        $data = [
            'message' => $statusMesasge
        ];
        $message = view('appointment', $data);
        $this->sendMail($email,  'Appointment update', $message,);
        return $this->response->setJSON(['message' => 'Appointment updated successfully']);
    }

    //delete appointment
    public function deleteAppointment()
    {
        $this->_secure();
        $mdl = new AppointmentsModel();
        $input = $this->request->getJSON();
        try {
            $appointment = $mdl->find($input->id);
            if (!$appointment) {
                return $this->response->setStatusCode(404)->setJSON(['error' => 'Not Found', 'message' => 'Appointment not found']);
            }
            if ($appointment && $appointment['citizen_id'] != $this->accessData->uid) {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Forbidden', 'message' => 'You are not allowed to delete this appointment']);
            } else if ($appointment && $appointment['status'] != 0) {
                return $this->response->setStatusCode(403)->setJSON(['error' => 'Forbidden', 'message' => 'You can\'t delete approved appointment']);
            }
            $mdl->delete($input->id);
            return $this->response->setJSON(['message' => 'Appointment deleted successfully']);
        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJSON(['error' => 'Error occurred', 'message' => $e->getMessage()]);
        }
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
        } else if ($this->accessData->typ == '2') {
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
    public function getActiveResidents($output = 0, $token = "")
    {
        $token == "" ? $this->_secure() : $this->_secure($token);
        $mdl = new UsersModel();
        $result = $mdl->select("users.id, users.names, users.email, users.phone, users.id_number, 'Resident' as type, users.status, COALESCE(s.total_issues, 0) as total_issues, COALESCE(s.resolved_issues, 0) as resolved_issues, COALESCE(ap.total_appointments, 0) as total_appointments, COALESCE(ap.approved_appointments, 0) as approved_appointments")
            ->join('issues i', 'i.user_id = users.id', 'left')
            ->join('appointments a', 'a.citizen_id = users.id', 'left')
            ->join('(Select COUNT(id) as total_issues, COUNT(CASE WHEN status = 1 THEN id END) as resolved_issues, user_id from issues group by user_id) s', 's.user_id = users.id', 'left')
            ->join('(Select COUNT(id) as total_appointments, COUNT(CASE WHEN status = 1 THEN id END) as approved_appointments, citizen_id from appointments group by citizen_id) ap', 'ap.citizen_id = users.id', 'left')
            ->where('users.type', 4)
            ->groupBy('users.id')
            ->get()->getResultArray();

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
                $worksheet->getStyle('A3:K3')->applyFromArray($styleArray);
                $worksheet->getCell('A3')->setValue('#');
                $worksheet->getCell('B3')->setValue('Names');
                $worksheet->getCell('C3')->setValue('Email');
                $worksheet->getCell('D3')->setValue('Phone');
                $worksheet->getCell('E3')->setValue('ID Number');
                $worksheet->getCell('F3')->setValue('Type');
                $worksheet->getCell('G3')->setValue('Status');
                $worksheet->getCell('H3')->setValue('Total Issues');
                $worksheet->getCell('I3')->setValue('Resolved Issues');
                $worksheet->getCell('J3')->setValue('Total Appointments');
                $worksheet->getCell('K3')->setValue('Approved Appointments');

                $i = 4;
                foreach ($result as $res) {
                    $worksheet->getCell('A' . $i)->setValue($res['id']);
                    $worksheet->getCell('B' . $i)->setValue($res['names']);
                    $worksheet->getCell('C' . $i)->setValue($res['email']);
                    $worksheet->getCell('D' . $i)->setValue($res['phone']);
                    $worksheet->getCell('E' . $i)->setValue($res['id_number']);
                    $worksheet->getCell('F' . $i)->setValue($res['type']);
                    $worksheet->getCell('G' . $i)->setValue($res['status']);
                    $worksheet->getCell('H' . $i)->setValue($res['total_issues']);
                    $worksheet->getCell('I' . $i)->setValue($res['resolved_issues']);
                    $worksheet->getCell('J' . $i)->setValue($res['total_appointments']);
                    $worksheet->getCell('K' . $i)->setValue($res['approved_appointments']);

                    $worksheet->getRowDimension($i)->setRowHeight(20);

                    $i++;
                }
                $worksheet->setTitle("List of Residents");
                $worksheet->getTabColor()->setARGB('FF058e8c');
                $writer = IOFactory::createWriter($spreadsheet, 'Xls');
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Access-Control-Expose-Headers: Content-Disposition');
                header('Content-Disposition: attachment; filename="List of Residents - ' . date('Y-m-d') . '.xls"');
                $writer->save("php://output");
            } catch (\Exception $e) {
                return $this->response->setStatusCode(500)->setJSON(["message" => $e->getMessage()]);
            }
        }
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
            return $this->response->setStatusCode(403)->setJSON(array(
                "type" => "error",
                "message" => "New password is not confirmed"
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
                    "type" => "success",
                    "message" => "Password changed successfully"
                ]);
            } catch (\Exception $e) {
                return $this->response->setStatusCode(500)->setJSON(array(
                    "error" => "Error occurred",
                    "message" => $e->getMessage()
                ));
            }
        } else {
            return $this->response->setStatusCode(500)->setJSON(array(
                "tyoe" => "error",
                "message" => "Invalid Current Password"
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

    //update user status
    public function changeUserStatus()
    {
        $this->_secure();
        $mdl = new UsersModel();
        $input = $this->request->getJSON();
        if ($this->accessData->typ > 2) { //only admin and leader can change user status
            return $this->response->setStatusCode(403)->setJSON(['error' => 'Forbidden', 'message' => 'You are not allowed to change your status']);
        }
        $data = [
            'status' => $input->status
        ];
        $mdl->update($input->id, $data);
        return $this->response->setJSON(['message' => 'User status updated successfully']);
    }

    public function editUserInfo()
    {
        $this->_secure();
        $mdl = new UsersModel();
        $input = $this->request->getJSON();
        $data = [
            'id' => $input->id,
            'names' => $input->names,
            'phone' => $input->phone,
            'email' => $input->email,
        ];
        $mdl->save($data);
        return $this->response->setJSON(['message' => 'User updated successfully']);
    }
}
