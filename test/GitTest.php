<?php
/**
 * Grynn\Git : GitTest.php
 * Run from a git working directory
 *
 * Copyright (c) 2015-2016, Vishal Doshi <vishal.doshi@gmail.com>.
 * All rights reserved.
 */

require_once __DIR__ . "/../vendor/autoload.php";

class GitTest extends PHPUnit_Framework_TestCase {
    private $git;

    protected function setUp()
    {
        parent::setUp();
        $this->git = new Grynn\Git();
    }

    public function testGetCurrentBranch()
    {
        $br = $this->git->getCurrentBranch();
        $this->assertTrue(is_string($br));
    }

    public function testIsDirty()
    {
        $ret = $this->git->isDirty();
        $this->assertTrue(is_bool($ret));
    }
}
