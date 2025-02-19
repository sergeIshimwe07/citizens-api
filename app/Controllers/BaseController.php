<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Class BaseController
 *
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 * Extend this class in any new controllers:
 *     class Home extends BaseController
 *
 * For security be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * Instance of the main Request object.
     *
     * @var CLIRequest|IncomingRequest
     */
    protected $request;

    /**
     * An array of helpers to be loaded automatically upon
     * class instantiation. These helpers will be available
     * to all other controllers that extend BaseController.
     *
     * @var array
     */
    protected $helpers = [];

    /**
     * Be sure to declare properties for any property fetch you initialized.
     * The creation of dynamic property is deprecated in PHP 8.2.
     */
    // protected $session;

    /**
     * @return void
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Do Not Edit This Line
        parent::initController($request, $response, $logger);

        // Preload any models, libraries, etc, here.

        // E.g.: $this->session = \Config\Services::session();
    }

    public function appendHeader()
    {
        if (strtoupper($_SERVER['REQUEST_METHOD']) == "OPTIONS") {
            $this->response->appendHeader('Access-Control-Allow-Origin', '*');
            $this->response->appendHeader('Access-Control-Allow-Methods', '*');
            $this->response->appendHeader('Access-Control-Allow-Credentials', 'true');

            $this->response->appendHeader('Access-Control-Allow-Headers', 'Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

            $this->response->setJSON(array("success", "okay"));
            $this->response->send();
            exit();
        }
        $this->response->appendHeader("Access-Control-Allow-Origin", "*");
        $this->response->appendHeader("Access-Control-Allow-Methods", "*");
        $this->response->appendHeader("Access-Control-Max-Age", 3600);
        $this->response->appendHeader("Access-Control-Allow-Headers", "Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
    }

    public function random_password($length=10): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890!&%$#@';
        $password = array();
        $alpha_length = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++)
        {
            $n = rand(0, $alpha_length);
            $password[] = $alphabet[$n];
        }
        $pass = implode($password);
        return $pass;
    }

    public function sendMail(string $email, string $subject, string $msg, String $institution = 'Tubura'): bool
    {
        $email1 = \Config\Services::email();
        $config = array("SMTPHost" => "mail.qonics.com", "SMTPUser" => "guarsy@qonics.com", "SMTPPass" => "9MNa3Vm065RQ"
            , "protocol" => "smtp", "SMTPPort" => 587, "mailType" => "html");
        $email1->initialize($config);
        $email1->setFrom("guarsy@qonics.com", "$institution");
        $email1->setTo($email);
        $email1->setSubject($subject);
        $email1->setMessage($msg);
        if ($email1->send(false)) {
            return true;
        }
        log_message('critical', 'email-issue: ' . json_encode($email1->printDebugger()));
        throw new \Exception("System failed to send email.", 400);
    }
}
