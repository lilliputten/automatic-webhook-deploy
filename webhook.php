<?php
/**
 * @module webhook
 * @version 2019.01.25, 21:45
 *
 * Bitbucket webhook interface.
 *
 * Based on 'Automated git deployment' script by Jonathan Nicoal:
 * http://jonathannicol.com/blog/2013/11/19/automated-git-deployments-from-bitbucket/
 *
 * See README.md and config.sample.php
 *
 * ---
 * Igor Lilliputten
 * mailto: igor at lilliputten dot ru
 * http://lilliputten.ru/
 *
 * Ivan Pushkin
 * mailto: iv dot pushk at gmail dot com
 */

// Initalize:
require_once('log.php');
require_once('core.php');

// Load config:
include('config.php');

// Let's go:
initConfig(); // Initialize repo configs
initLog(); // Initialize log variables
initPayload(); // Get posted data
fetchParams(); // Get parameters from bitbucket payload (REPO)
checkPaths(); // Check repository and project paths; create them if necessary
placeVerboseInfo(); // Place verbose log information if specified in config
fetchRepository(); // Fetch or clone repository
checkoutProject(); // Checkout project into target folder
