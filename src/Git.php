<?php
/**
 * Grynn\Git
 *
 * Copyright (c) 2015-2016, Vishal Doshi <vishal.doshi@gmail.com>.
 * All rights reserved.
 */

namespace Grynn;


class Git {

    /**
     * @var string GIT_DIR
     */
    private $wd;

    public function __construct($wd = null) {
        if (empty($wd)) {
            $wd = getcwd();
        }
        $this->wd = $wd;
    }

    /**
     * Runs a git command and returns it's output
     *
     * @param   string              $cmd    git command to be executed
     * @throws  \Grynn\Git\RuntimeException
     * @return  string              Output
     */
    public function git($cmd) {
        exec("git $cmd", $out, $code);
        if ($code != 0)
            throw new \RuntimeException(sprintf("Failed to run 'git %s'. Error Output=%s", $cmd, $out));
        return $out;
    }


    /**
     * @return string Current branch
     */
    public function getCurrentBranch() {
        return $this->git("rev-parse --abbrev-ref HEAD");
    }

    public function status($options = "") {
        $cmd = "status $options";
        return $this->git($cmd);

    }

    public function isDirty(){
        $status = $this->status("--porcelain");
        return strlen(trim($status)) != 0;
    }

    public function describe($options = ""){
        return $this->git("describe $options");
    }

    public function getVersionTag(){
        $tag = $this->describe("--tags --match v[0-9]*");
        return current(explode("-", $tag));
    }
}