<?php
class ControllerExtensionModuleBthemeSlideshow extends Controller {
    private $error = [];

    public function index() {
        $this->load->language('extension/module/btheme_slideshow');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/module');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            if (!isset($this->request->get['module_id'])) {
                $this->model_setting_module->addModule('btheme_slideshow', $this->request->post);
            } else {
                $this->model_setting_module->editModule($this->request->get['module_id'], $this->request->post);
            }
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('extension/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module'));
        }

        if (isset($this->request->get['module_id'])) {
            $module_info = $this->model_setting_module->getModule($this->request->get['module_id']);
        } else {
            $module_info = [];
        }

        $data['slides'] = $this->request->post['slides'] ?? ($module_info['slides'] ?? []);

        $data['action'] = $this->url->link('extension/module/btheme_slideshow', 'user_token=' . $this->session->data['user_token'] . (!empty($this->request->get['module_id']) ? '&module_id=' . $this->request->get['module_id'] : ''), true);
        $data['cancel'] = $this->url->link('extension/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

        $data['user_token'] = $this->session->data['user_token'];

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/btheme_slideshow', $data));
    }

    protected function validate() {
        if (!$this->user->hasPermission('modify', 'extension/module/btheme_slideshow')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        return !$this->error;
    }
}
