<?php

namespace Epiclub\Controller;

use Epiclub\Engine\AbstractController;

class AcquisitionLineController extends AbstractController
{
    public function modifyLine() {}

    /**
     * @deprecated Why we need this?
     */
    public function deleteLine() {
        throw new \Exception("Error Processing Request", 1);
    }
}
