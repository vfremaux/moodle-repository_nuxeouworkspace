<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This plugin is used to access nuxeo repository
 *
 * @since 2.4
 * @package repository_nuxeouworkspace
 * @copyright 2014 Rectorat de Rennes {@link www.ac-rennes.fr}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/repository/lib.php');
require_once($CFG->dirroot.'/local/nuxeolibs/NuxeoAutomationClient/NuxeoAutomationAPI.php');
require_once($CFG->dirroot.'/repository/nuxeouworkspace/locallib.php');
require_once($CFG->libdir.'/formslib.php');

/**
 * repository_nuxeouworkspace class
 * This is a class used to browse files from nuxeo
 *
 * @since 2.4
 * @package repository_nuxeouworkspace
 * @copyright 2014 Rectorat de Rennes {@link www.ac-rennes.fr}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_nuxeouworkspace extends repository {

    /**
     * server nuxeo requeste url
     *
     * @var string
     */
    private $url_nuxeo;

    /**
     * server nuxeo access url
     *
     * @var string
     */
    private $url_base;

    /**
     * URL de base vers laquelle l'utilisateur est redirigé quand il clique sur "Gestion".
     * @var unknown
     */
    private $url_base_user_manage;

    /**
     * nuxeo protal sso secret key
     *
     * @var string
     */
    private $secret_key;

    /**
     * logged user name
     *
     * @var string
     */
    private $user_name;

    /**
     *
     * @var array array documents manager in the plugin instance
     */
    private $documents;

    /**
     * instance of nuxeo class
     *
     * @var nuxeo instance
     */
    private $nuxeo;

    private $moodle_forms;

    public static $USER_WORKSPACE = 'UserWorkspaces';

    public static $WORKSPACE = 'workspaces';

    private $params;

    private $userworspacepath ;

    /* -------------------------- override function ----------------------------- */

    /**
     * Constructor of nuxeo plugin
     *
     * @param int $repositoryid
     * @param stdClass $context
     * @param array $options
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()) {
        global $CFG, $USER;

        parent::__construct($repositoryid, $context, $options);
        $this->set_params_options();

        // Get url base and construct requeste url.
        $this->url_base = $this->params['url'];
        $this->url_nuxeo = nuxeo::construct_nuxeo_url($this->url_base);
        $this->secret_key = $CFG->nuxeokey;
        $this->user_name = $USER->username; // 'U_Contrib1' ; //
        
        $this->nuxeo = new nuxeo($this->url_nuxeo, $this->user_name, $this->secret_key);
        $this->nuxeo->set_params($this->params);
    }

    /**
     * Get a file list from nuxeo
     *
     * @param string $path
     *            path of directory in nuxeo
     * @param string $page
     * @return array
     */
    public function get_listing($path = "/", $page = "") {
        global $OUTPUT, $SESSION;

        if ($this->userworspacepath == null) {
            $this->userworspacepath = $this->nuxeo->getuserworkspacePath();
        }

        // Last path visited.
        if (empty ( $path ) || $path == '') {
            $path = $this->getlastpath();
        }

        // List.
        $list = array();
        $list['list'] = array();
        $list['manage'] = $this->getmanage_url($path);
        $list['dynload'] = true;
        $list['nosearch'] = false;
        $list['nologin'] = true; // Not use logout icon.

        if (empty($path) || $path == '/') {
            $path = $this->userworspacepath;

            if ($path == null) {
                throw new repository_exception('repositoryerror', 'repository', '', '');
            }
        }

        $result = $this->nuxeo->getFilesFrom($path);

        // If erro appened.
        if (!empty($result->error)) {
            throw new repository_exception('repositoryerror', 'repository', '', $result->error);
        }

        $list['path'] = $this->buildpath($path);
        if (!empty($result->content)) {
            $documents = $result->content;
            $list['list'] = $this->getlisting($documents);
        }

        // Set current path.
        $SESSION->last_path = $path;
        return $list;
    }

    /**
     * Downloads a file from external repository and saves it in temp dir
     *
     * @throws moodle_exception when file could not be downloaded
     * @throws new repository_exception when some error append while downloading file
     *         from nuxeo
     *        
     * @param string $reference
     *            the content of files.reference field or result of
     *            function {@link repository_nuxeouworkspace::get_file_reference()}
     * @param string $filename
     *            filename (without path) to save the downloaded file in the
     *            temporary directory, if omitted or file already exists the new filename will be generated
     * @return array with elements:
     *         path: internal location of the file
     *         url: URL to the source (from parameters)
     */
    public function get_file($reference, $filename = '') {
        $ref = unserialize($reference);
        $filepath = $ref->filepath;
        $path = $this->prepare_file($filename); // Generate a unique temporary filename.

        $result = nuxeo::download($ref);
        if (!empty($result->error)) {
            throw new repository_exception('repositoryerror', 'repository', '', $result->error);
        }
        $tmp = fopen($path, 'w');
        if ($tmp) {
            fwrite($tmp, $result->content);

            return array(
                'path' => $path,
                'url' => $filepath
            );
        } else {
            unlink($path);
            throw new moodle_exception('errorwhiledownload', 'repository', '', $result->content);
        }
    }

    /**
     * Prepare file reference information
     *
     * @param string $source
     * @return string file referece
     */
    public function get_file_reference($source) {
        global $USER;

        $reference = new stdClass();
        $reference->filepath = $source;
        $reference->user = $this->user_name;
        $reference->secret_key = $this->secret_key;
        $reference->url_nuxeo = $this->url_nuxeo;
        $reference->downloadurl = '';

        if (optional_param('usefilereference', false, PARAM_BOOL)) {
            try {
                $fileinfo = $this->nuxeo->get_file_info($reference->filepath);

                if ($fileinfo != null) {
                    $title = $fileinfo['filename'];
                    if (empty($title) || $title == '') {
                        $title = $fileinfo['title'];
                    }
                    $reference->downloadurl = $this->get_file_download_url($fileinfo['id'], $title, $fileinfo['repository']);
                }
            } catch (moodle_exception $e) {
                throw new repository_exception('cannotcreatereference', 'repository_nuxeouworkspace');
            }
        }
        return serialize($reference);
    }

    /**
     * Returns information about file in this repository by reference
     * {@link repository::get_file_reference()}
     * {@link repository::get_file()}
     *
     * Returns null if file not found or is not readable
     *
     * @param stdClass $reference
     *            file reference db record
     * @return null stdClass has 'filepath' property
     */
    public function get_file_by_reference($reference) {

        $ref = unserialize($reference->reference);

        $fileinfo = $this->nuxeo->get_file_info($ref->filepath);

        if ($fileinfo == null) {
            return null;
        }

        if ($fileinfo['type'] == 'Picture') {

            $path = $this->prepare_file(''); // Generate a unique temporary filename.
            $result = nuxeo::download($ref);
            if (!empty($result->error)) {
                throw new repository_exception('repositoryerror', 'repository', '', $result->error);
            }

            $tmp = fopen($path, 'w');

            if ($tmp) {
                fwrite($tmp, $result->content);

                return (object) array (
                    'filepath' => $path
                );
            } else {
                unlink ( $path );
                throw new moodle_exception('errorwhiledownload', 'repository', '', $result->content);
            }
        } else {
            return (object) array(
                'filesize' => $fileinfo['size']
            );
        }
    }

    /**
     *
     * @param stored_file $storedfile the file that contains the reference
     * @param int $lifetime Number of seconds before the file should expire from caches (default 24 hours)
     * @param int $filter 0 (default)=no filtering, 1=all files, 2=html files only
     * @param bool $forcedownload If true (default false), forces download of file rather than view in browser/plugin
     * @param array $options additional options affecting the file serving
     */
    public function send_file($storedfile, $lifetime = 86400, $filter = 0, $forcedownload = false, array $options = null) {

        $ref = unserialize($storedfile->get_reference());

        if (! $this->nuxeo->fileExixt($ref)) {
            send_file_not_found();
        }

        $temp = $this->prepare_file(''); // Generate a unique temporary filename.
        $answer = nuxeo::download($ref);
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename='.basename($ref->downloadurl));
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
//         header('Content-Length: ' . filesize($temp));
        ob_clean();
        flush();
        readfile('tempstream');
        die ();
    }

    /**
     * Return the source information
     *
     * @param string $source
     * @return string
     */
    public function get_file_source_info($source) {
        global $USER;

        return 'Nuxeo ('.$USER->username.') : '.$source;
    }

    /**
     * User cannot use the external link
     *
     * @return int
     */
    function supported_returntypes() {
        if ($this->params['returntypes']) {
            return array_sum($this->params['returntypes']);
        }
        return FILE_EXTERNAL | FILE_REFERENCE | FILE_INTERNAL;
    }

    /**
     * Nuxeo plugin supports all kinds of files
     *
     * @return array
     */
    function supported_filetypes() {
        return '*';
    }

    /**
     * globals var name
     */
    public static function get_type_option_names() {
        return array(
            'pluginname'
        );
    }

    public function print_search() {
        $str = parent::print_search();
        return $str;
    }

    public function search($search_text, $page = 0) {
        global $OUTPUT, $SESSION;

        $path = $SESSION->last_path;
        if ($this->userworspacepath == null) {
            $this->userworspacepath = $this->nuxeo->getuserworkspacePath();
        }

        $ret = array();
        $ret['list'] = array();
        $ret['manage'] = $this->getmanage_url($path);
        $ret['dynload'] = true;
        $ret['nosearch'] = false;
        $ret['nologin'] = true; // Not use login icon.

        $result = $this->nuxeo->recherche($search_text, $path);
        if (!empty($result->error)) {
            throw new repository_exception('repositoryerror', 'repository', '', $result->error);
        }
        $docs = $result->content;
        $ret['path'] = $this->buildpath($path);
        $ret['list'] = $this->getlisting($docs);

        return $ret;
    }

    /**
     * return the last path visited or set if empty
     *
     * @return string
     */
    private function getlastpath() {
        global $SESSION;

        $lastpath = '/';

        if (!empty($SESSION->last_path)) {
            $lastpath = $SESSION->last_path;
        }
        return $lastpath;
    }

    /**
     *
     * @param string $path path to build
     * @return array
     */
    private function buildpath($path) {

        // Path root.
        $pathbuild = array (
            array (
                'name' => get_string('nuxeoRoot', 'repository_nuxeouworkspace'),
                'path' => '/'
            )
        );

        // Full path.
        $root = $this->userworspacepath;
        $pathToTrail = '';
        $pathToTrail = substr($path, strlen($root));

        $trail = $root;
        if (!empty($pathToTrail)) {
            $parts = explode('/', $pathToTrail);
            if (count($parts) > 1) {
                foreach ($parts as $part) {
                    if (!empty($part)) {
                        $trail .= ('/'.$part);
                        $pathbuild[] = array (
                            'name' => $part,
                            'path' => $trail
                        );
                    }
                }
            } else {
                $pathbuild[] = array (
                    'name' => $pathToTrail,
                    'path' => $pathToTrail
                );
            }
        }
        return $pathbuild;
    }

    /**
     *
     * @param array $documents
     *            result of function {@link nuxeo::getFilesFrom($path) or @link nuxeo::getRoot() }
     * @return array list of directories ans files
     */
    private function getlisting($documents) {
        global $OUTPUT;

        $listing = array();
        if (!is_array($documents)) {
            return $listing;
        }

        $dirslist = array();
        $fileslist = array();
        foreach ($documents as $file) {

            if (in_array($file->getType(), array_merge($this->params['conteners'], $this->params['space']))) {
                $type = $file->getType();

                if ($type === 'UserWorkspace') {
                    $type = 'Workspace';
                } else if ($type === 'OrderedFolder') {
                    $type = 'Folder';
                }

                $dirslist[] = array(
                    'title' => $file->getTitle(),
                    'path' => $file->getPath(),
                    'date' => $file->getProperty('dc:created'),
                    'thumbnail' => $OUTPUT->pix_url(strtolower($type), 'repository_nuxeouworkspace')->out(false),
                    'thumbnail_height' => 64,
                    'thumbnail_width' => 64,
                    'children' => array()
                );
            } else if ($file->getType() == 'Picture') {

                $title = $file->getPictureFilename();
                if (empty($title) || $title == '') {
                    $title = $file->getTitle();
                }

                $fileslist[] = array (
                    'title' => $title,
                    'source' => $file->getPath(),
                    'size' => $file->getProperty('common:size'),
                    'datecreated' => strtotime($file->getProperty('dc:created')),
                    'datemodified' => strtotime($file->getProperty('dc:modified')),
                    'author' => $file->getProperty('dc:creator'),
                    'thumbnail' => $OUTPUT->pix_url(file_extension_icon(".png", 64))->out(false),
                    'thumbnail_height' => 64,
                    'thumbnail_width' => 64
                );
            } else {
                $title = $file->getProperty('file:filename');
                if (empty($title) || $title == '') {
                    $title = $file->getTitle();
                }
                $fileslist[] = array(
                    'title' => $title,
                    'source' => $file->getPath(),
                    'size' => $file->getProperty('common:size'),
                    'datecreated' => strtotime($file->getProperty('dc:created')),
                    'datemodified' => strtotime($file->getProperty('dc:modified')),
                    'author' => $file->getProperty('dc:creator'),
                    'thumbnail' => $OUTPUT->pix_url(file_extension_icon($title, 64))->out(false),
                    'thumbnail_height' => 64,
                    'thumbnail_width' => 64
                );
            }
        }

        $listing = array_merge($dirslist, array_values($fileslist));
        return $listing;
    }

    private function getbasename($path) {
        $parts = explode('/', $path);
        $basename = '';
        // if (count($parts) > 1) {
        // foreach ($parts as $part) {
        // if (!empty($part)) {
        // $basename .= ('/'.$part);
        // if($part == self::$WORKSPACE || $part == self::$USER_WORKSPACE){
        // break ;
        // }

        // }
        // }
        // }
        return $basename;
    }

    private function getmanage_url($path) {
        $manage_url = $this->url_base_user_manage;

        if (empty($path) || $path == '' || $path == '/') {
            return $manage_url;
        }
        if ($manage_url[strlen($manage_url) - 1] != '/' && $path[0] != '/') {
            $manage_url .= '/';
        }
        if ($path[0] == '/') {
            $manage_url .= substr($path, 1);
        } else {
            $manage_url .= $path ;
        }

        return $manage_url;
    }

    private function get_file_download_url($uid, $fileName, $repository) {
        $download_url = $this->url_base;

        if ($download_url[strlen($download_url) - 1] != '/') {
            $download_url .= '/';
        }
        $download_url .= 'nxfile/'.$repository.'/'.$uid.'/blobholder:0/'.$fileName;
        return $download_url;
    }

    private function set_params_options() {

        try {
            $nuxeoconfig = get_config('local_nuxeolibs', 'xmlsettings');
            $settings = simplexml_load_string($nuxeoconfig);
            $admin_settings = $settings->admin_config;

            $this->params = array ();

            $this->params['space'] = array('UserWorkspace');

            $this->params['conteners'] = array();
            foreach ($admin_settings->conteners->entry as $entry) {
                $this->params['conteners'][] = (string) $entry;
            }

            $this->params['contents'] = array();
            foreach ($admin_settings->contents->entry as $entry) {
                $this->params['contents'][] = (string) $entry;
            }

            // Typereturn.
            $this->params['returntypes'] = array();
            foreach ($admin_settings->returntypes->entry as $entry) {
                if ((string) $entry['enable'] == 'true') {
                    $this->params['returntypes'][] = (int) $entry['value'];
                }
            }

            // Nuxeourl.
            $this->params['url'] = (string) $admin_settings->url;
            $this->url_base_user_manage = (string) $admin_settings->url_base_user_manage;
        } catch (Exception $e) {
            throw new moodle_exception('configerror', 'repository_nuxeouworkspace', '', $e->getMessage());
        }
    }
}


