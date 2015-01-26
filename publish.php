<?php
/**
 * publish.php
 *
 * Publish process:
 *   Check we're on master; check status != dirty; check unit tests pass; check functional tests pass
 *   Ask if user wants to see diff between last release and this release
 *   Ask if user wants to bump version tag
 *   Push code to remote 'origin'
 *   After push remote will deploy and create tag 'rem' (see example post-recieve hook in same dir as this file)
 *
 * Pre-requisites:
 *   composer must be in path
 *   git must be in path
 *   $PROJECT_ROOT/composer.json must exist
 *   $PROJECT_ROOT/{logs,test,web} must exist
 *   $PROJECT_ROOT/deploy.sh must exist (this will be executed on the remote)
 *   $PROJECT_ROOT/
 *
 * Copyright (c) 2015-2016, Vishal Doshi <vishal.doshi@gmail.com>.
 * All rights reserved.
 */
namespace Grynn;

require_once __DIR__ . "/vendor/autoload.php";

use CommandLine;

$st = microtime(true);

$climate = new \League\CLImate\CLImate;
$defaults = [
    'remote'=>'origin'
];

//Parse command line
$opt = parseCommandLine($argv);
if (isset($opt['remote'])) {
    $remote = $opt['remote'];
} else {
    $remote = $defaults['remote'];
}

//Get project root (root of git && has composer.json)
$climate->inline("Detecting project root: ");
$git = new Git();
$root = $git->getRoot();
if (!file_exists($root."/composer.json")) {
    errExit($climate, "Could not find composer.json! in $root");
    die;
}
$climate->green($root . " (" . round((microtime(true)  - $st)*1000) . " ms)");

//Validate composer.json
$st = microtime(true);
$climate->inline("Validating composer.json: ");
exec("composer validate", $output, $code);
if ($code != 0) {
    errExit($climate);
    echo join("\n",$output);
}
$climate->green("Ok" . " (" . round((microtime(true)  - $st)*1000) . " ms)");

//Check current branch
$st = microtime(true);
$climate->inline("Checking git branch: ");
if (($br = $git->getCurrentBranch()) !== "master") {
    if (isset($opt['force'])) {
        errExit($climate, "$br! Refusing to continue.");
        die;
    } else {
        $climate->bold()->red("$br!")->lightRed(" Expected master, but will continue because --force supplied).");
    }
}
$climate->green($br . " (" . round((microtime(true)  - $st)*1000) . " ms)");

//Check that wd is clean
$climate->inline("Checking if working directory is clean: ");
if (($dirty = $git->isDirty()) != false) {
    if (isset($opt['force']) && $opt['force']) {
        $climate->bold()->red()->inline("dirty!")->lightRed(" (but will continue, because --force supplied).");
    } else {
        errExit($climate, "Dirty! Refusing to continue");
    }
}

//Lint code
$climate->inline("Linting: ");
passthru("\"./vendor/bin/parallel-lint\" --exclude \"vendor/\" .", $code);
if ($code != 0) {
    $climate->error("lint failed");
    die;
}

//Run unit tests
$climate->inline("Looking for unit tests: ");
$composer = json_decode(file_get_contents($root . "/composer.json"));
if (isset($composer->scripts) && isset($composer->scripts->phpunit)) {
    $climate->green("{$composer->scripts->phpunit}");
    passthru("composer phpunit", $code);
} else {
    $testDir = "";
    $testDir = is_dir("$root/test") ? "$root/test" : is_dir("$root/tests") ? "$root/tests" : "";
    if (empty($testDir)) {
         $climate->red("none detected; (checked if composer script test OR $root/test(s)? exists)");
    }
    $climate->green("phpunit $root/test");
    passthru("phpunit " . __DIR__ . "/test", $code);
}
if ($code != 0) {
    die ("Unit tests failed.");
}

// Look for functional tests
$climate->inline("Checking for functional tests: ");
if (isset($composer->scripts) && isset($composer->scripts->test)) {
    $climate->green("{$composer->scripts->test}");
    passthru("composer test", $code);
} else {
    $climate->red("None found.");
}

// Check that a remote named 'web' exists
$climate->inline("Checking for remote '$remote': ");
$remotes = $git->exec("remote");
$remotes = explode("\n", $remotes);
$remotes = array_filter($remotes);
if (array_search($remote, $remotes)===false) {
    $climate->error("Not found! Cannot continue.");
    die;
} else {
    $climate->green("Ok");
}

// Check remote for tag 'rem' (if not found, check for branch master)
// Describe remote commit incl. lightweight tags
$climate->inline("Reading refs from remote '$remote' : ");
$remoteRefs = $git->lsRemote($remote);
$remoteSha = "";
//$climate->table(dumpTable($remoteRefs));
if (isset($remoteRefs['refs/tags/rem'])) {
    $remoteSha = $remoteRefs['refs/tags/rem'];
} else if (isset($remoteRefs['refs/heads/master'])) {
    $remoteSha = $remoteRefs['refs/heads/master'];
} else {
    if (count($remoteRefs)) {
        $climate->error("FAIL!");
        $climate->out("Could not determine remote version; neither tag 'rem' exists nor branch $remote/master.");
        $climate->out("Remote is not empty either!");
        $climate->table(dumpTable($remoteRefs));
        errExit($climate, "Could not read remote version");
    } else {
        $climate->red("looks empty...");
        if (!$climate->confirm("Continue? (y/n) ")->confirmed()) {
            errExit($climate, "Aborted by user");
        }
    }
}
$climate->green("Ok");


// Detect prev. version (web/master and/or tag rem)
// It's either web/master or tag rem in remote repo
$prev = "";
if (!empty($remoteSha)) {
    $climate->inline("Detecting prev. version: ");
    try {
        $prev = $git->describe("--tags --match \"v[0-9]*\" $remoteSha");
    } catch (\RuntimeException $e) {
        if (preg_match("/fatal: Not a valid object name/", $e->getMessage())) {
            $climate->error("!! remote has a version that's not tagged in this repo !!");
            if (!$climate->confirm("Continue? (y/n) ")->confirmed()) {
                errExit($climate, "Aborted by user");
            }
        } else {
            throw $e;
        }
    }
    $climate->green($prev . " ($remoteSha)");
}

$climate->inline("Detecting current version: ");
$version = $git->getVersionTag();
$sha = $git->exec("rev-parse HEAD");    //We know current HEAD points to master
$climate->green($version . " ($sha)");

//Show diff between this version and 'rem'
if (empty($prev)) {
    $climate->out("Changes");
} else {
    $climate->out("Changes since $prev");
}
$climate->out("----------------------------");
if (empty($remoteSha)) {
    $git->passthru("log --pretty=format:\" - %s\"");
} else {
    $git->passthru("log --pretty=format:\" - %s\" $remoteSha..HEAD");
}
$climate->out("");

//Show GUI diff
do {
    $resp = $climate->input("Show diff? (g)ui, (s)ummary, (c)ontinue, (a)bort : ")
        ->accept([ 'g','s','c','a' ])
        ->defaultTo('c')
        ->prompt();
    if ($resp === "s") {
        $git->passthru("diff --name-status $remoteSha");
    } elseif ($resp === "g") {
        $git->passthru("dt $remoteSha");
    } elseif ($resp === "a") {
        errExit($climate, "Aborted by user.");
    }
} while ($resp != "c" );


//Create a new version
if (strstr($version, "-") !== false) {
    $resp = $climate->input("Create new version? (c)ustom, (a)uto (q)uit : ")
        ->accept(['c','a','q'])
        ->defaultTo('a')
        ->prompt();
    if ($resp == "a") {
        $v1 = reset(explode("-", $version, 2));
        if (!preg_match('/v(?P<major>\d+)\.(?P<minor>\d+)\.(?P<patch>\d+)$/', $v1, $matches)) {
            errExit("Failed to parse version string");
        }
        $v2 = array_intersect_key($matches, array_flip(['major', 'minor', 'patch']));
        $v2['patch']++;
        $newVersion = "v{$v2['major']}.{$v2['minor']}.{$v2['patch']}";
        $climate->inline("Bumping version: ");
        $git->passthru("tag $newVersion");
        $version = $newVersion;
        $climate->green($newVersion);
    } elseif ($resp == "q") {
        errExit("Aborted by user");
    }
}

if (!$climate->confirm("Push changes to remote ($remote)?")
    ->defaultTo('n')
    ->confirmed()) {
    errExit($climate, "Aborted by user.");
}
$climate->green("Pushing...");
$git->passthru("push --tags $remote master");
//$climate->green("git push -f --tags $remote master");

/**
 * Parse command line options
 * @param $argv     string
 * @return object
 */
function parseCommandLine($argv)
{
    $args = CommandLine::parseArgs($_SERVER['argv']);
    if (isset($args['f'])) {
        $args['force'] = $args['f'];
    }
    if (isset($args['d'])) {
        $args['debug'] = $args['d'];
    }
    if (isset($args[0])) {
        $args['remote'] = $args[0];
    }

    return $args;
}


function errExit($climate, $msg="FAIL") {
    $climate->backgroundRed()->white()->bold($msg);
    die(1);
}

function hasTag($git, $tag) {
    $tags = $git->exec("tag -l");
    $tags = explode("\n", $tags);
    $tags = array_filter($tags);
    return (array_search($tag, $tags)!==false);
}

function dumpTable($arr) {
    $tmp[] = ['ref', 'hash'];
    foreach ($arr as $k => $v) {
        $tmp[] = [$k,$v];
    }
    return $tmp;
}
