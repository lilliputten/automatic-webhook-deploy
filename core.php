<?php
// vim:fdm=marker
/**
 * @module core
 * @changed 2026.04.28, 04:22
 *
 * Routines for work with bitbucket/github server, repositories and projects.
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

/*{{{ *** Global variables */

define('DEFAULT_FOLDER_MODE', 0755);

if (!defined('NL')) {
    define('NL', "\n");
}

$PAYLOAD = array();
$BRANCHES = array();
$REPO = ''; // Repository name (owner/name)
$REPO_FOLDER_NAME = ''; // Repository folder path (under `$REPOSITORIES_PATH` -- see config)
$REPO_TYPE = ''; // Repository type: bitbucket|github
$REPO_URL_PREFIX = ''; // Repo url prefix from `$REPO_URL_PREFIXES` for `$REPO_TYPE`
$REPO_URL_PREFIXES = array(
    // 'bitbucket-lilliputten' => 'git@bitbucket.org-lilliputten',
    'bitbucket' => 'git@bitbucket.org',
    'github' => 'git@github.com',
    'gitlab' => 'git@gitlab.com',
);

/*}}}*/

function initConfig()/*{{{ Initializing repo configs */
{
    global $CONFIG, $PROJECTS;

    $tmpProjects = array();

    // Bitbucket uses lower case repo names!
    $hadUppercaseKeys = false;
    foreach ($PROJECTS as $repoName => $config) {
        $tmpProjects[strtolower($repoName)] = $config;
        $hadUppercaseKeys = true;
    }

    // Rewrite projects list if has changes
    if ($hadUppercaseKeys) {
        $PROJECTS = $tmpProjects;
    }

    // Set default folder mode if absent
    if (empty($CONFIG['folderMode'])) {
        $CONFIG['folderMode'] = DEFAULT_FOLDER_MODE;
    }

    // NOTE: Log may be flushed in `_LOG_INIT` after `initConfig`
    // Do not use logging here!

}/*}}}*/
function initLog()/*{{{ Initializing log variables */
{
    _LOG_INIT();

}/*}}}*/
function initPayload()/*{{{ Get posted data */
{
    global $CONFIG, $PAYLOAD, $REPO_TYPE, $REPO_URL_PREFIXES, $REPO_URL_PREFIX;

    // EXAMPLE:
    // HTTP_X_EVENT_KEY|HTTP_X_GITHUB_EVENT=repo:push
    // HTTP_X_HOOK_UUID|HTTP_X_GITHUB_DELIVERY=5233528b-6d0d-4f41-a155-3b1c0dc2c566
    // HTTP_USER_AGENT=Bitbucket-Webhooks/2.0

    $event = isset($_SERVER['HTTP_X_EVENT_KEY']) ? $_SERVER['HTTP_X_EVENT_KEY'] : $_SERVER['HTTP_X_GITHUB_EVENT'];
    $hook = isset($_SERVER['HTTP_X_HOOK_UUID']) ? $_SERVER['HTTP_X_HOOK_UUID'] : $_SERVER['HTTP_X_GITHUB_DELIVERY'];
    $agent = $_SERVER['HTTP_USER_AGENT'];
    $addr = $_SERVER['REMOTE_ADDR'];

    if (!empty($event) && !empty($hook) && !empty($agent) && !empty($addr)) {
        _LOG('*** ' . $event . ' #' . $hook . ' (' . $agent . '@' . $addr . ')');
    } else {
        _LOG('*** Cannot detect event, hook id, remote agent or address!');
    }

    if (isset($_POST['payload'])) { // old method
        $PAYLOAD = $_POST['payload'];
    } else { // new method
        $PAYLOAD = json_decode(file_get_contents('php://input'));
    }

    if (empty($PAYLOAD)) {
        _ERROR("No payload data for checkout!");
        exit;
    }

    if ($CONFIG['logPayload']) {
        _LOG_VAR('PAYLOAD', $PAYLOAD);
    }

    // Check for correct payload data received...
    if (isset($PAYLOAD->repository->name)) {
        // Bitbucket mode (changes list)...
        if (isset($PAYLOAD->push->changes)) {
            _LOG("Detected bitbucket mode (changes list)");
            $REPO_TYPE = 'bitbucket';
        }
        // Gitlab mode...
        else if (isset($PAYLOAD->project->web_url) && str_starts_with($PAYLOAD->project->web_url, 'https://gitlab.com')) {
            _LOG("Detected gitlab mode");
            $REPO_TYPE = 'gitlab';
        }
        // Github mode (one branch)...
        else if (isset($PAYLOAD->ref)) {
            _LOG("Detected github mode (one branch)");
            $REPO_TYPE = 'github';
        }
        // Error???
    }

    if (empty($REPO_TYPE)) {
        _ERROR("Invalid payload data was received -- Cannot detect repository mode (bitbucket, github)!");
        exit;
    }

    // Determine url prefix by repository type
    $REPO_URL_PREFIX = $REPO_URL_PREFIXES[$REPO_TYPE];

    if (empty($REPO_URL_PREFIX)) {
        _ERROR("Invalid payload data was received -- Cannot detect repository url prefix!");
        exit;
    }

    _LOG('Repository url prefix: ' . $REPO_URL_PREFIX);

}/*}}}*/
function fetchParams()/*{{{ Get parameters from bitbucket payload now only (REPO) */
{
    global $CONFIG, $REPO, $REPO_FOLDER_NAME, $REPO_TYPE, $PAYLOAD, $PROJECTS, $BRANCHES;

    // Get repository name:
    $REPO = strtolower($PAYLOAD->repository->full_name);
    if (empty($REPO) && isset($PAYLOAD->repository->url)) {
        $REPO = $PAYLOAD->repository->url;

        // Extract repository path from GitLab URL format (git@gitlab.com:path/to/repo.git)
        if (preg_match('/git@gitlab\.com:(.*)\.git/', $REPO, $matches)) {
            $REPO = $matches[1];
            _LOG("Extracted repository path from GitLab URL: $REPO");
        }
    }
    _LOG_VAR('Repository', $REPO);
    if (empty($PROJECTS[$REPO])) {
        _ERROR("Not found repository config for '$REPO'!");
        exit;
    }

    // Fetch branches...

    // Bitbucket mode (changes list)...
    if ($REPO_TYPE === 'bitbucket') {
        _LOG("Bitbucket mode (changes list)");
        foreach ($PAYLOAD->push->changes as $change) {
            // TODO: Fetch branch name for github from `$PAYLOAD->ref`
            if (is_object($change->new) && $change->new->type == "branch") {
                $branchName = $change->new->name;
                _LOG("Found bitbucket branch: " . $branchName);
                if (isset($PROJECTS[$REPO][$branchName])) {
                    // Create branch name for checkout
                    array_push($BRANCHES, $branchName);
                    _LOG("Found changes in branch: " . $branchName);
                }
            }
        }
    }
    // Github mode (one branch)...
    else if ($REPO_TYPE === 'gitlab') {
        _LOG("Github mode (one branch)");
        $branchName = preg_replace('/refs\/heads\//', '', $PAYLOAD->ref);
        _LOG("Found gitlab branch: " . $branchName);
        if (isset($PROJECTS[$REPO][$branchName])) {
            // Create branch name for checkout
            array_push($BRANCHES, $branchName);
            _LOG("Found changes in branch: " . $branchName);
        }
    }
    // Github mode (one branch)...
    else if ($REPO_TYPE === 'github') {
        _LOG("Github mode (one branch)");
        $branchName = preg_replace('/refs\/heads\//', '', $PAYLOAD->ref);
        _LOG("Found github branch: " . $branchName);
        if (isset($PROJECTS[$REPO][$branchName])) {
            // Create branch name for checkout
            array_push($BRANCHES, $branchName);
            _LOG("Found changes in branch: " . $branchName);
        }
    }

    if (empty($BRANCHES)) {
        _ERROR("Nothing to update (no branches found)! Please check correct branch names in your config PROJECTS list.");
        // TODO: exit?
    }

    // Construct repository folder name
    // NOTE: ATTENTION 2018.10.23, 20:45 -- Sometimes
    // `$PAYLOAD->repository->name` has repository name in free form (with
    // spaces etc). Now using two-level folders structure in `repositoriesPath`
    // -- repositories stored with specified usernames.
    // $REPO_FOLDER_NAME = strtolower($PAYLOAD->repository->name); // OLD buggy (?) code.
    $REPO_FOLDER_NAME = $REPO_TYPE . '-' . preg_replace('/\//', '-', $REPO) . '.git';
    _LOG_VAR('Repository folder name', $REPO_FOLDER_NAME);


}/*}}}*/
function checkPaths()/*{{{ Check repository and project paths; create them if neccessary */
{
    global $REPO, $CONFIG, $PROJECTS, $BRANCHES;

    // Check for repositories folder path; create if absent
    $repoRoot = $CONFIG['repositoriesPath'];
    if (!is_dir($repoRoot)) {
        $mode = $CONFIG['folderMode'];
        if (mkdir($repoRoot, $mode, true)) {
            chmod($repoRoot, $mode); // NOTE: Ensuring folder mode!
            _LOG("Creating root repositories folder '" . $repoRoot . " (" . decoct($mode) . ") for '$REPO'");
        } else {
            _ERROR("Error creating root repositories folder '" . $repoRoot . " for '$REPO'! Exiting.");
            exit;
        }
    }

    // Create folder if absent for each pushed branch
    foreach ($BRANCHES as $branchName) {
        $deployPath = $PROJECTS[$REPO][$branchName]['deployPath'];
        if (empty($deployPath)) {
            _ERROR("Not specified deployPath for '$REPO' branch '$branchName'! Exiting.");
            exit;
        }
        if (!is_dir($deployPath)) {
            $mode = $CONFIG['folderMode'];
            if (mkdir($deployPath, $mode, true)) {
                chmod($deployPath, $mode); // NOTE: Ensuring folder mode!
                _LOG("Creating project folder '" . $deployPath .
                    " (" . decoct($mode) . ") for '$REPO' branch '$branchName'");
            } else {
                _ERROR("Error creating project folder '" . $deployPath .
                    "' for '$REPO' branch '$branchName'! Exiting.");
                exit;
            }
        }
    }

}/*}}}*/
function placeVerboseInfo()/*{{{ Place verbose log information -- if specified in config */
{
    global $CONFIG; // , $REPO, $BRANCHES;

    if ($CONFIG['verbose']) {
        // _LOG_VAR('REPO',$REPO);
        // _LOG_VAR('BRANCHES',$BRANCHES);
    }

}/*}}}*/
function fetchRepository()/*{{{ Fetch or clone repository */
{
    global $REPO, $REPO_FOLDER_NAME, $REPO_URL_PREFIX, $CONFIG, $PROJECT_OPTIONS, $PROJECTS, $BRANCHES, $PAYLOAD;

    // Compose current repository path
    $repoRoot = $CONFIG['repositoriesPath'];
    $repoPath = $repoRoot . DIRECTORY_SEPARATOR . $REPO_FOLDER_NAME;

    // If repository or repository folder are absent then clone full repository
    if (!is_dir($repoPath) || !is_file($repoPath . DIRECTORY_SEPARATOR . 'HEAD')) {
        _LOG("Repository folder absent for '$REPO', cloning...");

        $repoUrlPrefix = $REPO_URL_PREFIX;
        $repoUrlPostfix = $PROJECT_OPTIONS[$REPO]['repoUrlPostfix'];
        if (!empty($repoUrlPostfix)) {
            $repoUrlPrefix .= $repoUrlPostfix;
        }
        $repoUrl = $repoUrlPrefix . ':' . $REPO . '.git';

        $cmd = 'cd "' . $repoRoot . '" && ' . $CONFIG['gitCommand']
            . ' clone --mirror ' . $repoUrl . ' "' . $REPO_FOLDER_NAME . '" 2>&1';
        _LOG_VAR('cmd', $cmd);
        exec($cmd, $output, $status);

        if ($status !== 0) {
            _ERROR('Cannot clone repository ' . $repoUrl . ': ' . NL . implode(NL, $output));
            exit;
        }
    }
    // Else fetch changes
    else {
        _LOG("Repository folder exists for '$REPO', fetching...");

        $cmd = 'cd "' . $repoPath . '" && ' . $CONFIG['gitCommand'] . ' fetch 2>&1';
        _LOG_VAR('cmd', $cmd);
        // system($cmd, $status);
        exec($cmd, $output, $status);

        if ($status !== 0) {
            _ERROR("Cannot fetch repository '$REPO' in '$repoPath': " . NL . implode(NL, $output));
            exit;
        }
    }

}/*}}}*/
function checkoutProject()/*{{{ Checkout project into target folder */
{
    global $REPO, $REPO_FOLDER_NAME, $CONFIG, $PROJECTS, $BRANCHES;

    // Compose current repository path
    $repoPath = $CONFIG['repositoriesPath'] . DIRECTORY_SEPARATOR . $REPO_FOLDER_NAME;

    // Checkout project files
    foreach ($BRANCHES as $branchName) {

        $deployPath = $PROJECTS[$REPO][$branchName]['deployPath'];

        $cmd = 'cd "' . $repoPath . '" && GIT_WORK_TREE="' . $deployPath . '" ' . $CONFIG['gitCommand'] . ' checkout -f ' . $branchName . ' 2>&1';
        _LOG_VAR('cmd', $cmd);
        // system($cmd, $status);
        exec($cmd, $output, $status);

        if ($status !== 0) {
            _ERROR("Cannot checkout branch '$branchName' in repo '$REPO': " . NL . implode(NL, $output));
            exit;
        }

        $postHookCmd = $PROJECTS[$REPO][$branchName]['postHookCmd'];
        if (!empty($postHookCmd)) {
            $cmd = 'cd "' . $deployPath . '" && ' . $postHookCmd . ' 2>&1';
            _LOG_VAR('cmd', $cmd);
            // system($cmd, $status);
            exec($cmd, $output, $status);

            if ($status !== 0) {
                _ERROR("Error in post hook command for branch '$branchName' in repo '$REPO': " . NL . implode(NL, $output));
                exit;
            }
        }

        // Log the deployment
        // TODO: Catch output & errors (` 2>&1`)???
        $cmd = 'cd "' . $repoPath . '" && ' . $CONFIG['gitCommand'] . ' rev-parse --short ' . $branchName;
        _LOG_VAR('cmd', $cmd);
        $hash = rtrim(shell_exec($cmd));

        _LOG("Branch '$branchName' was deployed in '" . $deployPath . "', commit #$hash");
    }
}/*}}}*/
