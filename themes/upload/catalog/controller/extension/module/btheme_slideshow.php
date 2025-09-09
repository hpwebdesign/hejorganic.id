<?php
class ControllerExtensionModuleBthemeSlideshow extends Controller {
    public function index($setting) {
        $data['slides'] = $setting['slides'] ?? [];
        return $this->load->view('extension/module/btheme_slideshow', $data);
    }
}
