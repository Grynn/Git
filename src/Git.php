<?php
/**
 * Grynn\Git
 *
 * Copyright (c) 2015-2016, Vishal Doshi <vishal.doshi@gmail.com>.
 * All rights reserved.
 */

namespace Grynn;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

class Git implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var string GIT_DIR
     */
    protected $wd;

    public function __construct($wd = null, LoggerInterface $logger = null)
    {
        if (empty($logger)) {
            $this->logger = new NullLogger();
        } else {
            $this->logger = $logger;
        }

        if (empty($wd)) {
            $wd = getcwd();
        }

        $wd = trim($this->exec("--work-tree \"$wd\" rev-parse --show-toplevel", false));

        $this->logger->debug("Setting GIT_WORK_TREE=$wd",[__FUNCTION__]);
        $this->wd = $wd;
    }

    public function getRoot() {
        return $this->wd;
    }

    public function lsRemote($remote) {
        $lines = $this->exec("ls-remote $remote");
        $lines = explode("\n",$lines);
        $lines = array_filter($lines);  //remove blanks
        $ret=[];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line, 2, PREG_SPLIT_NO_EMPTY);
            $ret[$parts[1]] = $parts[0];
        }
        return $ret;
    }

    /**
     * Runs a git command and returns it's output
     *
     * @param   string              $cmd    git command to be executed
     * @throws  RuntimeException
     * @return  string              Output
     */
    public function exec($cmd) {
        $cmd = "git --work-tree \"{$this->wd}\" $cmd 2>&1";
        list($code, $out) = $this->myexec($cmd);
        if ($code != 0)
            throw new \RuntimeException(sprintf("Failed to run '%s'. Error Output=%s", $cmd, $out));

        return $out;
    }

    public function passthru($cmd) {
        $cmd = "git --work-tree \"{$this->wd}\" $cmd 2>&1";
        list($code, $out) = $this->myexec($cmd, true);
        if ($code != 0)
            throw new \RuntimeException(sprintf("Failed to run '%s'. Error Output=%s", $cmd, $out));

        return $out;
    }

    /**
     * @return string Current branch
     */
    public function getCurrentBranch() {
        return trim($this->exec("rev-parse --abbrev-ref HEAD"));
    }

    public function status($options = "") {
        $cmd = "status $options";
        return $this->exec($cmd);
    }

    /**
     * @return bool Returns true if working directory is dirty
     */
    public function isDirty(){
        $status = $this->status("--porcelain");
        return !empty(trim($status));
    }

    public function describe($options = "--tags"){
        return $this->exec("describe $options");
    }

    public function getVersionTag($ref = ""){
        return $this->describe("--tags --match v[0-9]* --dirty --always $ref");
    }



    /**
     * Run a command, optionally displaying output.
     * To capture stderr as well as stdout, $cmd should contain "2>&1"
     *
     * @param $cmd  string      To capture stderr as well as stdout, $cmd should contain "2>&1"
     * @param bool $passthru    Display output?
     * @return array            [$code, $stdout]
     */
    protected function myexec($cmd, $passthru = false)
    {
        if ($passthru) {
            ob_start();
            $this->logger->debug("Executing (passthru) $cmd\n");
            passthru($cmd, $code);
            $output = ob_get_flush();
        } else {
            $this->logger->debug("Executing (exec) $cmd\n");
            exec($cmd, $output, $code);
            $output = join("\n", $output );     //stderr not captured by default
        }

        return [$code, $output];
    }
}