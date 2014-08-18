<?php
/**
 * wallabag, self hostable application allowing you to not miss any content anymore
 *
 * @category   wallabag
 * @author     Nicolas Lœuillet <nicolas@loeuillet.org>
 * @copyright  2013
 * @license    http://opensource.org/licenses/MIT see COPYING file
 */

class Poche
{
    /**
     * @var User
     */
    public $user;
    /**
     * @var Database
     */
    public $store;
    /**
     * @var Template
     */
    public $tpl;
    /**
     * @var Language
     */
    public $language;
    /**
     * @var Routing
     */
    public $routing;
    /**
     * @var Messages
     */
    public $messages;
    /**
     * @var Paginator
     */
    public $pagination;

    public function __construct()
    {
        $this->init();
    }

    private function init()
    {
        Tools::initPhp();

        $pocheUser = Session::getParam('poche_user');

        if ($pocheUser && $pocheUser != array()) {
            $this->user = $pocheUser;
        } else {
            // fake user, just for install & login screens
            $this->user = new User();
            $this->user->setConfig($this->getDefaultConfig());
        }

        $this->pagination   = new Paginator($this->user->getConfigValue('pager'), 'p');
        $this->language     = new Language($this);
        $this->tpl          = new Template($this);
        $this->store        = new Database();
        $this->messages     = new Messages();
        $this->routing      = new Routing($this);
    }

    public function run()
    {
        $this->routing->run();
    }

    /**
     * Creates a new user
     */
    public function createNewUser($username, $password)
    {
        if (!empty($username) && !empty($password)){
            $newUsername = filter_var($username, FILTER_SANITIZE_STRING);
            if (!$this->store->userExists($newUsername)){
                if ($this->store->install($newUsername, Tools::encodeString($password . $newUsername))) {
                    Tools::logm('The new user ' . $newUsername . ' has been installed');
                    $this->messages->add('s', sprintf(_('The new user %s has been installed. Do you want to <a href="?logout">logout ?</a>'), $newUsername));
                    Tools::redirect();
                }
                else {
                    Tools::logm('error during adding new user');
                    Tools::redirect();
                }
            }
            else {
                $this->messages->add('e', sprintf(_('Error : An user with the name %s already exists !'), $newUsername));
                Tools::logm('An user with the name ' . $newUsername . ' already exists !');
                Tools::redirect();
            }
        }
    }

    /**
     * Delete an existing user
     */
    public function deleteUser($password)
    {
        if ($this->store->listUsers() > 1) {
            if (Tools::encodeString($password . $this->user->getUsername()) == $this->store->getUserPassword($this->user->getId())) {
                $username = $this->user->getUsername();
                $this->store->deleteUserConfig($this->user->getId());
                Tools::logm('The configuration for user '. $username .' has been deleted !');
                $this->store->deleteTagsEntriesAndEntries($this->user->getId());
                Tools::logm('The entries for user '. $username .' has been deleted !');
                $this->store->deleteUser($this->user->getId());
                Tools::logm('User '. $username .' has been completely deleted !');
                Session::logout();
                Tools::logm('logout');
                Tools::redirect();
                $this->messages->add('s', sprintf(_('User %s has been successfully deleted !'), $username));
            }
            else {
                Tools::logm('Bad password !');
                $this->messages->add('e', _('Error : The password is wrong !'));
            }
        }
        else {
            Tools::logm('Only user !');
            $this->messages->add('e', _('Error : You are the only user, you cannot delete your account !'));
        }
    }

    public function getDefaultConfig()
    {
        return array(
            'pager' => PAGINATION,
            'language' => LANG,
            'theme' => DEFAULT_THEME
        );
    }

    /**
     * Call action (mark as fav, archive, delete, etc.)
     */
    public function action($action, Url $url, $id = 0, $import = FALSE, $autoclose = FALSE, $tags = null)
    {
        switch ($action)
        {
            case 'add':
                $content = Tools::getPageContent($url);
                $title = ($content['rss']['channel']['item']['title'] != '') ? $content['rss']['channel']['item']['title'] : _('Untitled');
                $body = $content['rss']['channel']['item']['description'];

                // clean content from prevent xss attack
                $purifier = $this->_getPurifier();
                $title = $purifier->purify($title);
                $body = $purifier->purify($body);

                //search for possible duplicate
                $duplicate = NULL;
                $duplicate = $this->store->retrieveOneByURL($url->getUrl(), $this->user->getId());

                $last_id = $this->store->add($url->getUrl(), $title, $body, $this->user->getId());
                if ( $last_id ) {
                    Tools::logm('add link ' . $url->getUrl());
                    if (DOWNLOAD_PICTURES) {
                        $content = Picture::filterPicture($body, $url->getUrl(), $last_id);
                        Tools::logm('updating content article');
                        $this->store->updateContent($last_id, $content, $this->user->getId());
                    }

                    if ($duplicate != NULL) {
                        // duplicate exists, so, older entry needs to be deleted (as new entry should go to the top of list), BUT favorite mark and tags should be preserved
                        Tools::logm('link ' . $url->getUrl() . ' is a duplicate');
                        // 1) - preserve tags and favorite, then drop old entry
                        $this->store->reassignTags($duplicate['id'], $last_id);
                        if ($duplicate['is_fav']) {
                          $this->store->favoriteById($last_id, $this->user->getId());
                        }
                        if ($this->store->deleteById($duplicate['id'], $this->user->getId())) {
                          Tools::logm('previous link ' . $url->getUrl() .' entry deleted');
                        }
                    }

                    $this->messages->add('s', _('the link has been added successfully'));
                }
                else {
                    $this->messages->add('e', _('error during insertion : the link wasn\'t added'));
                    Tools::logm('error during insertion : the link wasn\'t added ' . $url->getUrl());
                }

                if ($autoclose == TRUE) {
                  Tools::redirect('?view=home');
                } else {
                  Tools::redirect('?view=home&closewin=true');
                }
                break;
            case 'delete':
                $msg = 'delete link #' . $id;
                if ($this->store->deleteById($id, $this->user->getId())) {
                    if (DOWNLOAD_PICTURES) {
                        Picture::removeDirectory(ABS_PATH . $id);
                    }
                    $this->messages->add('s', _('the link has been deleted successfully'));
                }
                else {
                    $this->messages->add('e', _('the link wasn\'t deleted'));
                    $msg = 'error : can\'t delete link #' . $id;
                }
                Tools::logm($msg);
                Tools::redirect('?');
                break;
            case 'toggle_fav' :
                $this->store->favoriteById($id, $this->user->getId());
                Tools::logm('mark as favorite link #' . $id);
                if ( Tools::isAjaxRequest() ) {
                  echo 1;
                  exit;
                }
                else {
                  Tools::redirect();
                }
                break;
            case 'toggle_archive' :
                $this->store->archiveById($id, $this->user->getId());
                Tools::logm('archive link #' . $id);
                if ( Tools::isAjaxRequest() ) {
                  echo 1;
                  exit;
                }
                else {
                  Tools::redirect();
                }
                break;
            case 'archive_all' :
                $this->store->archiveAll($this->user->getId());
                Tools::logm('archive all links');
                Tools::redirect();
                break;
            case 'add_tag' :
                if (isset($_GET['search'])) {
                    //when we want to apply a tag to a search
                    $tags = array($_GET['search']);
                    $allentry_ids = $this->store->search($tags[0], $this->user->getId());
                    $entry_ids = array();
                    foreach ($allentry_ids as $eachentry) {
                        $entry_ids[] = $eachentry[0];
                    }
                } else { //add a tag to a single article
                    $tags = explode(',', $_POST['value']);
                    $entry_ids = array($_POST['entry_id']);
                }
                foreach($entry_ids as $entry_id) {
                    $entry = $this->store->retrieveOneById($entry_id, $this->user->getId());
                    if (!$entry) {
                        $this->messages->add('e', _('Article not found!'));
                        Tools::logm('error : article not found');
                        Tools::redirect();
                    }
                    //get all already set tags to preven duplicates
                    $already_set_tags = array();
                    $entry_tags = $this->store->retrieveTagsByEntry($entry_id);
                    foreach ($entry_tags as $tag) {
                      $already_set_tags[] = $tag['value'];
                    }
                    foreach($tags as $key => $tag_value) {
                        $value = trim($tag_value);
                        if ($value && !in_array($value, $already_set_tags)) {
                          $tag = $this->store->retrieveTagByValue($value);
                          if (is_null($tag)) {
                              # we create the tag
                              $tag = $this->store->createTag($value);
                              $sequence = '';
                              if (STORAGE == 'postgres') {
                                  $sequence = 'tags_id_seq';
                              }
                              $tag_id = $this->store->getLastId($sequence);
                          }
                          else {
                              $tag_id = $tag['id'];
                          }

                          # we assign the tag to the article
                          $this->store->setTagToEntry($tag_id, $entry_id);
                        }
                    }
                }
                $this->messages->add('s', _('The tag has been applied successfully'));
                Tools::logm('The tag has been applied successfully');
                Tools::redirect();
                break;
            case 'remove_tag' :
                $tag_id = $_GET['tag_id'];
                $entry = $this->store->retrieveOneById($id, $this->user->getId());
                if (!$entry) {
                    $this->messages->add('e', _('Article not found!'));
                    Tools::logm('error : article not found');
                    Tools::redirect();
                }
                $this->store->removeTagForEntry($id, $tag_id);
                Tools::logm('tag entry deleted');
                if ($this->store->cleanUnusedTag($tag_id)) {
                    Tools::logm('tag deleted');
                }
                $this->messages->add('s', _('The tag has been successfully deleted'));
                Tools::redirect();
                break;
            default:
                break;
        }
    }

    function displayView($view, $id = 0)
    {
        $tpl_vars = array();

        switch ($view)
        {
            case 'config':
                $dev_infos = $this->_getPocheVersion('dev');
                $dev = trim($dev_infos[0]);
                $check_time_dev = date('d-M-Y H:i', $dev_infos[1]);
                $prod_infos = $this->_getPocheVersion('prod');
                $prod = trim($prod_infos[0]);
                $check_time_prod = date('d-M-Y H:i', $prod_infos[1]);
                $compare_dev = version_compare(POCHE, $dev);
                $compare_prod = version_compare(POCHE, $prod);
                $themes = $this->tpl->getInstalledThemes();
                $languages = $this->language->getInstalledLanguages();
                $token = $this->user->getConfigValue('token');
                $http_auth = (isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['REMOTE_USER'])) ? true : false;
                $only_user = ($this->store->listUsers() > 1) ? false : true;
                $tpl_vars = array(
                    'themes' => $themes,
                    'languages' => $languages,
                    'dev' => $dev,
                    'prod' => $prod,
                    'check_time_dev' => $check_time_dev,
                    'check_time_prod' => $check_time_prod,
                    'compare_dev' => $compare_dev,
                    'compare_prod' => $compare_prod,
                    'token' => $token,
                    'user_id' => $this->user->getId(),
                    'http_auth' => $http_auth,
                    'only_user' => $only_user
                );
                Tools::logm('config view');
                break;
            case 'edit-tags':
                # tags
                $entry = $this->store->retrieveOneById($id, $this->user->getId());
                if (!$entry) {
                    $this->messages->add('e', _('Article not found!'));
                    Tools::logm('error : article not found');
                    Tools::redirect();
                }
                $tags = $this->store->retrieveTagsByEntry($id);
                $tpl_vars = array(
                    'entry_id' => $id,
                    'tags' => $tags,
                    'entry' => $entry,
                );
                break;
            case 'tags':
                $token = $this->user->getConfigValue('token');
                //if term is set - search tags for this term
                $term = Tools::checkVar('term');
                $tags = $this->store->retrieveAllTags($this->user->getId(), $term);
                if (Tools::isAjaxRequest()) {
                  $result = array();
                  foreach ($tags as $tag) {
                    $result[] = $tag['value'];
                  }
                  echo json_encode($result);
                  exit;
                }
                $tpl_vars = array(
                    'token' => $token,
                    'user_id' => $this->user->getId(),
                    'tags' => $tags,
                );
                break;
            case 'search':
                if (isset($_GET['search'])) {
                   $search = filter_var($_GET['search'], FILTER_SANITIZE_STRING);
                   $tpl_vars['entries'] = $this->store->search($search, $this->user->getId());
                   $count = count($tpl_vars['entries']);
                   $this->pagination->set_total($count);
                   $page_links = str_replace(array('previous', 'next'), array(_('previous'), _('next')),
                            $this->pagination->page_links('?view=' . $view . '?search=' . $search . '&sort=' . $_SESSION['sort'] . '&' ));
                   $tpl_vars['page_links'] = $page_links;
                   $tpl_vars['nb_results'] = $count;
                   $tpl_vars['search_term'] = $search;
                }
                break;
            case 'view':
                $entry = $this->store->retrieveOneById($id, $this->user->getId());
                if ($entry != NULL) {
                    Tools::logm('view link #' . $id);
                    $content = $entry['content'];
                    if (function_exists('tidy_parse_string')) {
                        $tidy = tidy_parse_string($content, array('indent'=>true, 'show-body-only' => true), 'UTF8');
                        $tidy->cleanRepair();
                        $content = $tidy->value;
                    }

                    # flattr checking
                    $flattr = new FlattrItem();
                    $flattr->checkItem($entry['url'], $entry['id']);

                    # tags
                    $tags = $this->store->retrieveTagsByEntry($entry['id']);

                    $tpl_vars = array(
                        'entry' => $entry,
                        'content' => $content,
                        'flattr' => $flattr,
                        'tags' => $tags
                    );
                }
                else {
                    Tools::logm('error in view call : entry is null');
                }
                break;
            default: # home, favorites, archive and tag views
                $tpl_vars = array(
                    'entries' => '',
                    'page_links' => '',
                    'nb_results' => '',
                    'listmode' => (isset($_COOKIE['listmode']) ? true : false),
                );

                //if id is given - we retrieve entries by tag: id is tag id
                if ($id) {
                  $tpl_vars['tag'] = $this->store->retrieveTag($id, $this->user->getId());
                  $tpl_vars['id'] = intval($id);
                }

                $count = $this->store->getEntriesByViewCount($view, $this->user->getId(), $id);

                if ($count > 0) {
                    $this->pagination->set_total($count);
                    $page_links = str_replace(array('previous', 'next'), array(_('previous'), _('next')),
                        $this->pagination->page_links('?view=' . $view . '&sort=' . $_SESSION['sort'] . (($id)?'&id='.$id:'') . '&' ));
                    $tpl_vars['entries'] = $this->store->getEntriesByView($view, $this->user->getId(), $this->pagination->get_limit(), $id);
                    $tpl_vars['page_links'] = $page_links;
                    $tpl_vars['nb_results'] = $count;
                }
                Tools::logm('display ' . $view . ' view');
                break;
        }

        return $tpl_vars;
    }

    /**
     * update the password of the current user.
     * if MODE_DEMO is TRUE, the password can't be updated.
     * @todo add the return value
     * @todo set the new password in function header like this updatePassword($newPassword)
     * @return boolean
     */
    public function updatePassword($password, $confirmPassword)
    {
        if (MODE_DEMO) {
            $this->messages->add('i', _('in demo mode, you can\'t update your password'));
            Tools::logm('in demo mode, you can\'t do this');
            Tools::redirect('?view=config');
        }
        else {
            if (isset($password) && isset($confirmPassword)) {
                if ($password == $confirmPassword && !empty($password)) {
                    $this->messages->add('s', _('your password has been updated'));
                    $this->store->updatePassword($this->user->getId(), Tools::encodeString($password . $this->user->getUsername()));
                    Session::logout();
                    Tools::logm('password updated');
                    Tools::redirect();
                }
                else {
                    $this->messages->add('e', _('the two fields have to be filled & the password must be the same in the two fields'));
                    Tools::redirect('?view=config');
                }
            }
        }
    }

    /**
     * Get credentials from differents sources
     * It redirects the user to the $referer link
     *
     * @return array
     */
    private function credentials()
    {
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            return array($_SERVER['PHP_AUTH_USER'], 'php_auth', true);
        }
        if (!empty($_POST['login']) && !empty($_POST['password'])) {
            return array($_POST['login'], $_POST['password'], false);
        }
        if (isset($_SERVER['REMOTE_USER'])) {
            return array($_SERVER['REMOTE_USER'], 'http_auth', true);
        }

        return array(false, false, false);
    }

    /**
     * checks if login & password are correct and save the user in session.
     * it redirects the user to the $referer link
     * @param  string $referer the url to redirect after login
     * @todo add the return value
     * @return boolean
     */
    public function login($referer)
    {
        list($login,$password,$isauthenticated)=$this->credentials();
        if($login === false || $password === false) {
            $this->messages->add('e', _('login failed: you have to fill all fields'));
            Tools::logm('login failed');
            Tools::redirect();
        }
        if (!empty($login) && !empty($password)) {
            $user = $this->store->login($login, Tools::encodeString($password . $login), $isauthenticated);
            if ($user != array()) {
                # Save login into Session
                $longlastingsession = isset($_POST['longlastingsession']);
                $passwordTest = ($isauthenticated) ? $user['password'] : Tools::encodeString($password . $login);
                Session::login($user['username'], $user['password'], $login, $passwordTest, $longlastingsession, array('poche_user' => new User($user)));

                # reload l10n
                $language = $user['config']['language'];
                @putenv('LC_ALL=' . $language);
                setlocale(LC_ALL, $language);
                bindtextdomain($language, LOCALE);
                textdomain($language);

                $this->messages->add('s', _('welcome to your wallabag'));
                Tools::logm('login successful');
                Tools::redirect($referer);
            }
            $this->messages->add('e', _('login failed: bad login or password'));
            Tools::logm('login failed');
            Tools::redirect();
        }
    }

    /**
     * log out the poche user. It cleans the session.
     * @todo add the return value
     * @return boolean
     */
    public function logout()
    {
        $this->user = array();
        Session::logout();
        Tools::logm('logout');
        Tools::redirect();
    }

    /**
     * import datas into your wallabag
     * @return boolean
     */
    public function import()
    {
        if (isset($_FILES['file'])) {
            Tools::logm('Import stated: parsing file');

            // assume, that file is in json format

            $str_data = file_get_contents($_FILES['file']['tmp_name']);
            $data = json_decode($str_data, true);
            if ($data === null) {

                // not json - assume html

                $html = new simple_html_dom();
                $html->load_file($_FILES['file']['tmp_name']);
                $data = array();
                $read = 0;
                foreach(array('ol','ul') as $list) {
                    foreach($html->find($list) as $ul) {
                        foreach($ul->find('li') as $li) {
                            $tmpEntry = array();
                            $a = $li->find('a');
                            $tmpEntry['url'] = $a[0]->href;
                            $tmpEntry['tags'] = $a[0]->tags;
                            $tmpEntry['is_read'] = $read;
                            if ($tmpEntry['url']) {
                                $data[] = $tmpEntry;
                            }
                        }

                        // the second <ol/ul> is for read links

                        $read = ((sizeof($data) && $read) ? 0 : 1);
                    }
                }
            }

            // for readability structure

            foreach($data as $record) {
                if (is_array($record)) {
                    $data[] = $record;
                    foreach($record as $record2) {
                        if (is_array($record2)) {
                            $data[] = $record2;
                        }
                    }
                }
            }

            $urlsInserted = array(); //urls of articles inserted
            foreach($data as $record) {
                $url = trim(isset($record['article__url']) ? $record['article__url'] : (isset($record['url']) ? $record['url'] : ''));
                if ($url and !in_array($url, $urlsInserted)) {
                    $title = (isset($record['title']) ? $record['title'] : _('Untitled - Import - ') . '</a> <a href="./?import">' . _('click to finish import') . '</a><a>');
                    $body = (isset($record['content']) ? $record['content'] : '');
                    $isRead = (isset($record['is_read']) ? intval($record['is_read']) : (isset($record['archive']) ? intval($record['archive']) : 0));
                    $isFavorite = (isset($record['is_fav']) ? intval($record['is_fav']) : (isset($record['favorite']) ? intval($record['favorite']) : 0));

                    // insert new record

                    $id = $this->store->add($url, $title, $body, $this->user->getId() , $isFavorite, $isRead);
                    if ($id) {
                        $urlsInserted[] = $url; //add
                        if (isset($record['tags']) && trim($record['tags'])) {

                            // @TODO: set tags

                        }
                    }
                }
            }

            $i = sizeof($urlsInserted);
            if ($i > 0) {
                $this->messages->add('s', _('Articles inserted: ') . $i . _('. Please note, that some may be marked as "read".'));
            }

            Tools::logm('Import of articles finished: ' . $i . ' articles added (w/o content if not provided).');
        }

        // file parsing finished here
        // now download article contents if any
        // check if we need to download any content

        $recordsDownloadRequired = $this->store->retrieveUnfetchedEntriesCount($this->user->getId());

        if ($recordsDownloadRequired == 0) {

            // nothing to download

            $this->messages->add('s', _('Import finished.'));
            Tools::logm('Import finished completely');
            Tools::redirect();
        }
        else {

            // if just inserted - don't download anything, download will start in next reload

            if (!isset($_FILES['file'])) {

                // download next batch

                Tools::logm('Fetching next batch of articles...');
                $items = $this->store->retrieveUnfetchedEntries($this->user->getId() , IMPORT_LIMIT);
                $purifier = $this->_getPurifier();
                foreach($items as $item) {
                    $url = new Url(base64_encode($item['url']));
                    Tools::logm('Fetching article ' . $item['id']);
                    $content = Tools::getPageContent($url);
                    $title = (($content['rss']['channel']['item']['title'] != '') ? $content['rss']['channel']['item']['title'] : _('Untitled'));
                    $body = (($content['rss']['channel']['item']['description'] != '') ? $content['rss']['channel']['item']['description'] : _('Undefined'));

                    // clean content to prevent xss attack

                    $title = $purifier->purify($title);
                    $body = $purifier->purify($body);
                    $this->store->updateContentAndTitle($item['id'], $title, $body, $this->user->getId());
                    Tools::logm('Article ' . $item['id'] . ' updated.');
                }
            }
        }

        return array(
            'includeImport' => true,
            'import' => array(
                'recordsDownloadRequired' => $recordsDownloadRequired,
                'recordsUnderDownload' => IMPORT_LIMIT,
                'delay' => IMPORT_DELAY * 1000
            )
        );
    }

    /**
     * export poche entries in json
     * @return json all poche entries
     */
    public function export()
    {
      $filename = "wallabag-export-".$this->user->getId()."-".date("Y-m-d").".json";
      header('Content-Disposition: attachment; filename='.$filename);

      $entries = $this->store->retrieveAll($this->user->getId());
      echo $this->tpl->render('export.twig', array(
          'export' => Tools::renderJson($entries),
      ));
      Tools::logm('export view');
    }

    /**
     * Checks online the latest version of poche and cache it
     * @param  string $which 'prod' or 'dev'
     * @return string        latest $which version
     */
    private function _getPocheVersion($which = 'prod') {
      $cache_file = CACHE . '/' . $which;
      $check_time = time();

      # checks if the cached version file exists
      if (file_exists($cache_file) && (filemtime($cache_file) > (time() - 86400 ))) {
         $version = file_get_contents($cache_file);
         $check_time = filemtime($cache_file);
      } else {
         $version = file_get_contents('http://static.wallabag.org/versions/' . $which);
         file_put_contents($cache_file, $version, LOCK_EX);
      }
      return array($version, $check_time);
    }

    /**
     * Update token for current user
     */
    public function updateToken()
    {
        $token = Tools::generateToken();
        $this->store->updateUserConfig($this->user->getId(), 'token', $token);
        $currentConfig = $_SESSION['poche_user']->config;
        $currentConfig['token'] = $token;
        $_SESSION['poche_user']->setConfig($currentConfig);
        Tools::redirect();
    }

    /**
     * Generate RSS feeds for current user
     *
     * @param $token
     * @param $user_id
     * @param $tag_id
     * @param string $type
     */
    public function generateFeeds($token, $user_id, $tag_id, $type = 'home')
    {
        $allowed_types = array('home', 'fav', 'archive', 'tag');
        $config = $this->store->getConfigUser($user_id);

        if ($config == null) {
            die(sprintf(_('User with this id (%d) does not exist.'), $user_id));
        }

        if (!in_array($type, $allowed_types) || !isset($config['token']) || $token != $config['token']) {
            die(_('Uh, there is a problem while generating feed. Wrong token used?'));
        }

        $feed = new FeedWriter(RSS2);
        $feed->setTitle('wallabag — ' . $type . ' feed');
        $feed->setLink(Tools::getPocheUrl());
        $feed->setChannelElement('pubDate', date(DATE_RSS , time()));
        $feed->setChannelElement('generator', 'wallabag');
        $feed->setDescription('wallabag ' . $type . ' elements');

        if ($type == 'tag') {
            $entries = $this->store->retrieveEntriesByTag($tag_id, $user_id);
        }
        else {
            $entries = $this->store->getEntriesByView($type, $user_id);
        }

        if (count($entries) > 0) {
            foreach ($entries as $entry) {
                $newItem = $feed->createNewItem();
                $newItem->setTitle($entry['title']);
                $newItem->setSource(Tools::getPocheUrl() . '?view=view&amp;id=' . $entry['id']);
                $newItem->setLink($entry['url']);
                $newItem->setDate(time());
                $newItem->setDescription($entry['content']);
                $feed->addItem($newItem);
            }
        }

        $feed->genarateFeed();
        exit;
    }



    /**
     * Returns new purifier object with actual config
     */
    private function _getPurifier()
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', CACHE);
        $config->set('HTML.SafeIframe', true);

        //allow YouTube, Vimeo and dailymotion videos
        $config->set('URI.SafeIframeRegexp', '%^(https?:)?//(www\.youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/|www\.dailymotion\.com/embed/video/)%');

        return new HTMLPurifier($config);
    }


}
