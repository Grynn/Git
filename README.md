# Grynn\Git

Simple PHP wrapper for Git (depends on git executable being in the path). Very limited feature set. Mainly for
use with Grynn\GitVersionBump

## Installation

To add this package as a local, per-project dependency to your project, simply add a dependency on `grynn/git` to your project's `composer.json` file. Here is a minimal example of a `composer.json` file that just defines a dependency on Grynn\Git 1.0:

    {
        "require": {
            "grynn/git": "1.0.*"
        }
    }

## Usage

    $git = new \Grynn\Git();
    $git->getCurrentBranch();   //Returns: branchname
    $git->isDirty();            //Returns: true if working dir is dirty
    $git->getVersionTag();       //Returns: version tag that describes current HEAD
