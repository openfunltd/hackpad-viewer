<?php
class ErrorController extends MiniEngine_Controller
{
    public function errorAction($error)
    {
        $this->view->domain = HackpadHelper::getCurrentDomain();
        $this->view->user   = HackpadHelper::getCurrentUser();
        $this->view->error  = $error;

        $is404 = ($error instanceof MiniEngine_Controller_NotFound);

        // Log non-404 errors
        if (!$is404) {
            error_log("Error: " . $error->getMessage() . " in " . $error->getFile() . ":" . $error->getLine());
        }

        header($is404 ? 'HTTP/1.1 404 Not Found' : 'HTTP/1.1 500 Internal Server Error');
    }
}
