<?php
namespace Aplazo\AplazoPayment\Logger;

class Logger extends \Monolog\Logger
{
    public function setName($name)
    {
        $this->name = $name;
    }
}
