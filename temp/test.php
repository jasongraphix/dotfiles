<?php

class IndexController extends MC_Controller_ACL_Manager {


    public function preRun(){
        $this->requireAuth();
    }
    protected $_whitelisted_methods = array(
        "indexAction" => "viewer",
        "saveUserSettingAction" => "viewer",
        "markCoachmarkAction" => "viewer"
    );

    public function indexAction() {
        $user = Avesta::registry('user');

        # certain things like security questions will pass a referrer over to this root index action
        if($this->request->getParam('referrer')) $this->_redirect($this->request->getParam('referrer'));

        if($user->isViewer()) {
            $this->_redirect('/reports/');
        } else if($user->isAuthor()) {
            $this->_redirect('/campaigns');
        }

        // Don't show any reconfirm campaigns as recent.
        $recent_campaigns = $this->db->query('FROM Campaign.content WHERE status="sent" and type != "reconfirm" and type != "variate-child" ORDER BY send_time DESC LIMIT 10');

        $draft_campaigns = $this->db->query('FROM Campaign.content WHERE status="save" and type != "variate-child" ORDER BY create_time DESC LIMIT 20');
        $has_campaigns =  (bool) $this->db->querySqlOne('SELECT COUNT(*) FROM campaigns WHERE type!="auto" AND campaigns.is_deleted = "N"');

        $all_lists = $this->db->query('FROM MemberList.paid');
        $chatter = array_slice($this->db->findAll('Chatter'), 0, Chatter::DEFAULT_PAGE_SIZE);

        $has_ga_data = (bool) $this->db->querySqlOne('SELECT COUNT(*) FROM campaigns_analytics');
        $has_e360_data = (bool) $this->db->querySqlOne('SELECT COUNT(*) FROM ecomm_orders WHERE campaign_id != 0');

        if ($user->isBrandNew()){
            Avesta::setFlash('Looks like you missed a step - mind <a href="'.avesta_url('/signup/new-user/step1').'">filling out a bit more info</a>?', 'info', false);
        }

        $login_db = $this->getDatabase('login');
        $pending_invites = count($login_db->query('FROM LoginInvitation where user_id = ?', array($user->getID())));
        $has_invited_users = (bool) (count($user->getLogins()) > 1 || $pending_invites);

        return array('all_lists' => $all_lists, 'draft_campaigns' => $draft_campaigns, 'recent_campaigns' => $recent_campaigns, 'has_campaigns' => $has_campaigns, 'has_invited_users' => $has_invited_users, 'blog' => MC_Blog::getItems(), 'twitter' => MC_Blog::getTwitterItems(), 'all_chatter' => $chatter,
                     'has_ga_data'=>$has_ga_data, 'has_e360_data'=>$has_e360_data, 'all_chatter_size' => $this->db->querySqlOne('select count(*) from chatter'), 'section_class' => 'main-dashboard');
    }

    public function saveUserSettingAction() {
        $this->response->setHeader('Content-type', 'application/json');
        $key = trim(strip_tags($this->request->getParam('key')));å
        $value = trim(strip_tags($this->request->getParam('value')));

        // convert false/true strings to boolean counterparts
        if (is_string($value) && (strtoupper($value) === 'TRUE' || strtoupper($value) === 'FALSE')) {
            $value = strtoupper($value) === 'TRUE';
        }

        if (!$key || $value === null) {
            return json_encode(array('success'=>false, 'error'=>'Requires key and value'));
        }

        if(strlen($key) >= 50 || strlen($value) >= 50) {
            return json_encode(array('success' => false, 'error'=>'Invalid key or value'));
        }

        Avesta::registry('user')->setSetting($key, $value);

        return json_encode(array('success'=>true));
    }

    public function saveSessionSettingAction() {
        $this->response->setHeader('Content-type', 'application/json');
        $key = trim(strip_tags($this->request->getParam('key')));
        $value = trim(strip_tags($this->request->getParam('value')));

        // convert false/true strings to boolean counterparts
        if (is_string($value) && (strtoupper($value) === 'TRUE' || strtoupper($value) === 'FALSE')) {
            $value = strtoupper($value) === 'TRUE';
        }

        if (!$key || $value === null) {
            return json_encode(array('success'=>false, 'error'=>'Requires key and value'));
        }

        if(strlen($key) >= 50 || strlen($value) >= 50) {
            return json_encode(array('success' => false, 'error'=>'Invalid key or value'));
        }

        MC_Utils::setSessionSetting($key, $value);

        return json_encode(array('success'=>true));
    }

    public function clearEditorOnboardaction() {
        if(!MC_Utils::isRealProd()) {
            $user = Avesta::registry('user');
            $user->setSetting("classic-tour-completed", null);
        }
        $this->_redirect("/");
    }

    public function markCoachmarkAction() {
        $coachmark = $this->request->getParam('coachmark');
        $step = (integer) $this->request->getParam('step');
        if(!$step) $step = 1;

        $this->forward404Unless($coachmark);

        $user = Avesta::registry('user');
        $user->markCoachmark($coachmark, $step);

        $this->response->setHeader('Content-type', 'application/json');
        return json_encode(array('success'=>true));
    }

    /**
     * This is for testing purposes, so that we can reset coachmarks.
     */
    public function clearCoachmarksAction() {
        if(!MC_Utils::isRealProd()) {
            $old = $this->request->getParam('old', false);
            $user = Avesta::registry('user');

            if($old) {
                $user->setSetting("coachmarks", "replicate-campaign,list-add-subscriber,list-add-subs,search-within-list,list-import-subs");
            } else {
                $user->setSetting("coachmarks", null);
            }
        }

        $this->_redirect("/");
    }

    public function clearAbIntroAction() {
        if(!MC_Utils::isRealProd()) {
            $user = Avesta::registry("user");
            $user->setSetting(Campaign_Variate::AB_INTRO_USER_SETTING, false);
        }
        $this->_redirect("/lists");
    }

    public function clearInboxPreviewIntroAction() {
        if(!MC_Utils::isRealProd()) {
            $user = Avesta::registry("user");
            $user->setSetting(InboxPreview::INTRO_USER_SETTING, false);
        }
        $this->_redirect("/campaigns");
    }

    public function resetInboxPreviewTokensAction() {
        if(!MC_Utils::isRealProd()) {
            $user = Avesta::registry("user");
            $tokens = $this->request->getParam('tokens', 100);

            $db = Avesta_Db_Session::getSession('default');
            $entries = $db->query('FROM InboxPreview_Tokens');
            foreach($entries as $entry) {
                $entry->delete();
            }
            InboxPreview_Tokens::deposit($tokens, InboxPreview_TokensLog::EVENT_CREDIT);
            return $this->json(array('success' => true, 'tokens' => $tokens));
        }
    }

    public function setScreenSizeAction() {
        if ($this->request->getParam('screen_size', false)) {
            $_SESSION['screen_size'] = $this->request->getParam('screen_size');
        }
        $this->response->setHeader('Content-type', 'application/json');
        return json_encode(array('success'=>true));
    }

}
