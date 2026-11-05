<?php
/**
 * Plugin Name: Otomatik Randevu Programı — Danışan, Çoklu Danışman & Sekreter 3'lü Mutabakat Sistemi
 * Plugin URI: https://example.com/otomatik-randevu-programi
 * Description: Danışanın kendi iradesiyle onaylayıp WhatsApp grubuna katıldığı, gün ve saat önerdiği; Danışman ve Sekreter Hanım ile 3 kişi mutabık kalana kadar müzakerenin sürdüğü, mutabakat sağlandığında sitenin takviminde o gün ve saatin otomatik kapatıldığı ve e-posta bildirimlerinin gönderildiği tam otomatik WordPress randevu eklentisi. Sayfaya eklemek için [otomatik_randevu] kısa kodunu kullanın.
 * Version: 3.0.0
 * Author: Selim
 * Text Domain: otomatik-randevu
 */

if (!defined('ABSPATH')) {
    exit;
}

class Otomatik_Randevu_Programi {
    private $table_name;
    private $option_key = 'orp_settings_v3';

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'otomatik_randevular';

        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_shortcode('otomatik_randevu', array($this, 'render_booking_shortcode'));

        // AJAX Uç Noktaları
        add_action('wp_ajax_orp_get_slots', array($this, 'ajax_get_slots'));
        add_action('wp_ajax_nopriv_orp_get_slots', array($this, 'ajax_get_slots'));

        add_action('wp_ajax_orp_create_appointment', array($this, 'ajax_create_appointment'));
        add_action('wp_ajax_nopriv_orp_create_appointment', array($this, 'ajax_create_appointment'));

        add_action('wp_ajax_orp_respond_proposal', array($this, 'ajax_respond_proposal'));
        add_action('wp_ajax_nopriv_orp_respond_proposal', array($this, 'ajax_respond_proposal'));

        add_action('wp_ajax_orp_lookup_appointment', array($this, 'ajax_lookup_appointment'));
        add_action('wp_ajax_nopriv_orp_lookup_appointment', array($this, 'ajax_lookup_appointment'));

        // Hermes Agent & Dış Webhook Entegrasyonu
        add_action('rest_api_init', array($this, 'register_hermes_routes'));
        add_action('wp_ajax_orp_hermes_finalize', array($this, 'ajax_hermes_finalize'));
        add_action('wp_ajax_nopriv_orp_hermes_finalize', array($this, 'ajax_hermes_finalize'));
    }

    /**
     * Hermes Agent için REST API Uç Noktası
     */
    public function register_hermes_routes() {
        register_rest_route('otomatik-randevu/v1', '/finalize', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_hermes_finalize'),
            'permission_callback' => '__return_true'
        ));
    }

    public function rest_hermes_finalize($request) {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_params();
        }
        return $this->process_hermes_consensus($params);
    }

    public function ajax_hermes_finalize() {
        $params = $_POST;
        $res = $this->process_hermes_consensus($params);
        if ($res->get_status() === 200) {
            wp_send_json_success($res->get_data());
        } else {
            wp_send_json_error($res->get_data());
        }
    }

    private function process_hermes_consensus($params) {
        global $wpdb;
        $code  = isset($params['booking_code']) ? ltrim(strtoupper(sanitize_text_field($params['booking_code'])), '#') : '';
        $date  = isset($params['date']) ? sanitize_text_field($params['date']) : '';
        $time  = isset($params['time']) ? sanitize_text_field($params['time']) : '';
        $name  = isset($params['client_name']) ? sanitize_text_field($params['client_name']) : 'Danışan';
        $phone = isset($params['client_phone']) ? sanitize_text_field($params['client_phone']) : '';

        if (empty($date) || empty($time)) {
            return new WP_REST_Response(array('success' => false, 'message' => 'Tarih ve saat parametresi zorunludur.'), 400);
        }

        // Mevcut takip kodu varsa onu güncelle, yoksa yeni kayıt oluşturup kilitle
        $row = null;
        if (!empty($code)) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE booking_code = %s", $code), ARRAY_A);
        }

        $logs = array(
            array('time' => current_time('mysql'), 'by' => 'Hermes Agent', 'action' => "WhatsApp üzerinden 3 taraf mutabık kaldı: $date saat $time onaylandı.")
        );

        if ($row) {
            $wpdb->update($this->table_name, array(
                'appointment_date' => $date,
                'appointment_time' => $time,
                'client_ok'        => 1,
                'consultant_ok'    => 1,
                'secretary_ok'     => 1,
                'status'           => 'approved',
                'proposal_log'     => wp_json_encode($logs, JSON_UNESCAPED_UNICODE)
            ), array('id' => $row['id']));
            $apt = array_merge($row, array('appointment_date' => $date, 'appointment_time' => $time));
            $this->send_consensus_emails($apt);
            $booking_code = $row['booking_code'];
        } else {
            $booking_code = !empty($code) ? $code : 'RNV-' . strtoupper(wp_generate_password(4, false, false));
            $settings = $this->get_settings();
            $apt = array(
                'booking_code'      => $booking_code,
                'client_name'       => $name,
                'client_phone'      => $phone,
                'client_email'      => '',
                'voluntary_consent' => 1,
                'consultant_target' => 1,
                'service_id'        => 'yuz_yuze_ofis',
                'service_name'      => 'Danışmanlık Seansı',
                'session_mode'      => 'Ofiste Yüz Yüze',
                'appointment_date'  => $date,
                'appointment_time'  => $time,
                'duration_minutes'  => (int)$settings['slot_duration'],
                'client_notes'      => 'Hermes Agent WhatsApp mutabakatı ile otomatik oluşturuldu.',
                'proposed_by'       => 'Hermes Agent',
                'client_ok'         => 1,
                'consultant_ok'     => 1,
                'secretary_ok'      => 1,
                'proposal_log'      => wp_json_encode($logs, JSON_UNESCAPED_UNICODE),
                'group_status'      => 'in_group',
                'status'            => 'approved',
                'emails_sent'       => 1,
                'created_at'        => current_time('mysql')
            );
            $wpdb->insert($this->table_name, $apt);
            $this->send_consensus_emails($apt);
        }

        return new WP_REST_Response(array(
            'success'      => true,
            'booking_code' => $booking_code,
            'message'      => "Randevu (#$booking_code) $date $time için kesinleştirildi, sitede kilitlendi ve bildirim mailleri gönderildi."
        ), 200);
    }

    /**
     * Danışmanlar (1. Danışman, 2. Danışman / Ortak), Ofis Sekreteri ve Bildirim Ayarları
     */
    public function get_settings() {
        $defaults = array(
            'consultant_name'     => get_bloginfo('name') ?: '1. Danışman',
            'consultant_phone'    => '905320000000',
            'consultant_email'    => get_option('admin_email') ?: 'danisman@siteniz.com',
            'consultant_2_name'   => '',
            'consultant_2_phone'  => '',
            'consultant_2_email'  => '',
            'secretary_name'      => 'Sekreter Hanım',
            'secretary_phone'     => '905330000000',
            'secretary_email'     => 'sekreter@ofis.com',
            'office_name'         => 'Merkez Danışmanlık Ofisi',
            'whatsapp_group_name' => 'Randevu & Ofis Koordinasyon Grubu',
            'whatsapp_group_link' => 'https://chat.whatsapp.com/I4fDwRd1m010wmC1Ix7Ib2?s=cl&p=a&iam=2',
            'whatsapp_group_id'   => '',
            'whatsapp_api_url'    => '',
            'whatsapp_api_token'  => '',
            'slot_duration'       => 50,
            'buffer_time'         => 10,
            'work_start'          => '09:00',
            'work_end'            => '18:00',
            'break_start'         => '12:30',
            'break_end'           => '13:30',
            'work_days'           => array(1, 2, 3, 4, 5, 6),
            'blocked_dates'       => '',
            'voluntary_text'      => 'Danışmanlık ve ofis sekreteryası koordinasyon WhatsApp grubuna kendi özgür irademle katıldığımı, randevu gün ve saat mutabakatının bu iletişim kanalı üzerinden yürütülmesini onayladığımı beyan ederim.',
            'services'            => array(
                array(
                    'id'       => 'yuz_yuze_ofis',
                    'name'     => 'Ofiste Yüz Yüze Danışmanlık Seansı',
                    'duration' => 50,
                    'type'     => 'Ofis Odası + Sekreterya Karşılamalı'
                ),
                array(
                    'id'       => 'cift_aile',
                    'name'     => 'Çift & Aile Danışmanlığı (Ofis)',
                    'duration' => 60,
                    'type'     => 'Ofis Büyük Oda'
                ),
                array(
                    'id'       => 'online',
                    'name'     => 'Online Görüşme (Görüntülü)',
                    'duration' => 50,
                    'type'     => 'WhatsApp / Zoom'
                ),
                array(
                    'id'       => 'on_gorusme',
                    'name'     => 'İlk Tanışma & Randevu Planlama',
                    'duration' => 30,
                    'type'     => 'Ön Görüşme'
                )
            )
        );

        $saved = get_option($this->option_key, array());
        if (!is_array($saved)) {
            $saved = array();
        }
        return array_merge($defaults, $saved);
    }

    /**
     * Veritabanı Tablosu: İrade beyanı, 3'lü mutabakat ve müzakere loglarını saklar
     */
    public function activate_plugin() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_code varchar(32) NOT NULL,
            client_name varchar(120) NOT NULL,
            client_phone varchar(40) NOT NULL,
            client_email varchar(120) DEFAULT '' NOT NULL,
            voluntary_consent tinyint(1) DEFAULT 1 NOT NULL,
            consultant_target tinyint(1) DEFAULT 1 NOT NULL,
            service_id varchar(60) NOT NULL,
            service_name varchar(150) NOT NULL,
            session_mode varchar(40) DEFAULT 'Ofiste Yüz Yüze' NOT NULL,
            appointment_date date NOT NULL,
            appointment_time varchar(10) NOT NULL,
            duration_minutes int(11) DEFAULT 50 NOT NULL,
            client_notes text,
            proposed_by varchar(40) DEFAULT 'Danışan' NOT NULL,
            client_ok tinyint(1) DEFAULT 1 NOT NULL,
            consultant_ok tinyint(1) DEFAULT 0 NOT NULL,
            secretary_ok tinyint(1) DEFAULT 0 NOT NULL,
            proposal_log longtext,
            group_status varchar(30) DEFAULT 'invited' NOT NULL,
            status varchar(25) DEFAULT 'negotiating' NOT NULL,
            emails_sent tinyint(1) DEFAULT 0 NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY appointment_date (appointment_date),
            KEY booking_code (booking_code),
            KEY status (status)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        if (!get_option($this->option_key)) {
            update_option($this->option_key, $this->get_settings());
        }
    }

    /**
     * Telefon normalizasyonu (WhatsApp standardı)
     */
    public function normalize_phone($phone) {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strpos($digits, '0090') === 0) {
            $digits = substr($digits, 2);
        } elseif (strpos($digits, '05') === 0 && strlen($digits) === 11) {
            $digits = '9' . $digits;
        } elseif (strpos($digits, '5') === 0 && strlen($digits) === 10) {
            $digits = '90' . $digits;
        }
        return $digits;
    }

    /**
     * Sitedeki takvim saatlerini hesaplar:
     * 3 kişi mutabık kalıp onaylanan ('approved') saatler otomatik olarak KİLİTLENİR ve doludur.
     */
    public function calculate_day_slots($date_str) {
        global $wpdb;
        $settings = $this->get_settings();

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str)) {
            return array('available' => false, 'reason' => 'Geçersiz tarih.', 'slots' => array());
        }

        $timestamp = strtotime($date_str);
        $day_of_week = (int) date('w', $timestamp);

        $blocked = array_map('trim', explode(',', (string)$settings['blocked_dates']));
        if (in_array($date_str, $blocked, true)) {
            return array('available' => false, 'reason' => 'Seçilen tarihte ofis kapalıdır.', 'slots' => array());
        }

        if (!in_array($day_of_week, array_map('intval', (array) $settings['work_days']), true)) {
            return array('available' => false, 'reason' => 'Bu gün çalışma günü değildir.', 'slots' => array());
        }

        // Kesinleşen / onaylanan randevular günü ve saati sitede kalıcı kilitler
        $booked_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT appointment_time, duration_minutes FROM {$this->table_name} WHERE appointment_date = %s AND status = 'approved'",
                $date_str
            ),
            ARRAY_A
        );

        $booked_intervals = array();
        if (!empty($booked_rows)) {
            foreach ($booked_rows as $row) {
                $b_start = $this->time_to_minutes($row['appointment_time']);
                $b_end   = $b_start + (int) $row['duration_minutes'];
                $booked_intervals[] = array($b_start, $b_end);
            }
        }

        $work_start  = $this->time_to_minutes($settings['work_start']);
        $work_end    = $this->time_to_minutes($settings['work_end']);
        $break_start = $this->time_to_minutes($settings['break_start']);
        $break_end   = $this->time_to_minutes($settings['break_end']);
        $duration    = max(15, (int) $settings['slot_duration']);
        $buffer      = max(0, (int) $settings['buffer_time']);
        $step        = $duration + $buffer;

        $slots = array();
        for ($t = $work_start; $t + $duration <= $work_end; $t += $step) {
            $slot_end = $t + $duration;
            if ($break_end > $break_start && $t < $break_end && $slot_end > $break_start) {
                continue;
            }

            $time_label = $this->minutes_to_time($t);
            $is_booked = false;
            foreach ($booked_intervals as $interval) {
                if ($t < $interval[1] && $slot_end > $interval[0]) {
                    $is_booked = true;
                    break;
                }
            }

            $slots[] = array(
                'time'      => $time_label,
                'end_time'  => $this->minutes_to_time($slot_end),
                'available' => !$is_booked,
                'status'    => $is_booked ? 'booked' : 'free'
            );
        }

        return array(
            'available' => true,
            'date'      => $date_str,
            'slots'     => $slots
        );
    }

    private function time_to_minutes($time_str) {
        $parts = explode(':', $time_str);
        return ((int) $parts[0] * 60) + (isset($parts[1]) ? (int) $parts[1] : 0);
    }

    private function minutes_to_time($mins) {
        return sprintf('%02d:%02d', floor($mins / 60), $mins % 60);
    }

    public function ajax_get_slots() {
        $date = isset($_REQUEST['date']) ? sanitize_text_field($_REQUEST['date']) : '';
        wp_send_json_success($this->calculate_day_slots($date));
    }

    /**
     * 1. ADIM: Danışanın siteden irade onayı ile randevu başlatması ve gün/saat önermesi
     */
    public function ajax_create_appointment() {
        check_ajax_referer('orp_public_nonce', 'nonce');
        global $wpdb;

        $settings          = $this->get_settings();
        $client_name       = isset($_POST['client_name']) ? sanitize_text_field($_POST['client_name']) : '';
        $client_phone      = isset($_POST['client_phone']) ? sanitize_text_field($_POST['client_phone']) : '';
        $client_email      = isset($_POST['client_email']) ? sanitize_email($_POST['client_email']) : '';
        $voluntary_consent = !empty($_POST['voluntary_consent']) ? 1 : 0;
        $consultant_target = isset($_POST['consultant_target']) ? (int)$_POST['consultant_target'] : 1;
        $service_id        = isset($_POST['service_id']) ? sanitize_text_field($_POST['service_id']) : '';
        $session_mode      = isset($_POST['session_mode']) ? sanitize_text_field($_POST['session_mode']) : 'Ofiste Yüz Yüze';
        $date              = isset($_POST['appointment_date']) ? sanitize_text_field($_POST['appointment_date']) : '';
        $time              = isset($_POST['appointment_time']) ? sanitize_text_field($_POST['appointment_time']) : '';
        $notes             = isset($_POST['client_notes']) ? sanitize_textarea_field($_POST['client_notes']) : '';

        // İrade beyanı kontrolü
        if (!$voluntary_consent) {
            wp_send_json_error(array('message' => 'Lütfen WhatsApp grubuna kendi iradenizle katıldığınıza ve koordinasyonu kabul ettiğinize dair beyanı onaylayınız.'));
        }

        if (empty($client_name) || empty($client_phone) || empty($date) || empty($time)) {
            wp_send_json_error(array('message' => 'Lütfen Ad Soyad, WhatsApp Telefonu, Önerilen Tarih ve Saat alanlarını eksiksiz doldurun.'));
        }

        // Seçilen tarih ve saatin sitede kilitli (dolu) olup olmadığını doğrula
        $day_info = $this->calculate_day_slots($date);
        $slot_valid = false;
        foreach ($day_info['slots'] as $sl) {
            if ($sl['time'] === $time && $sl['available']) {
                $slot_valid = true;
                break;
            }
        }
        if (!$slot_valid) {
            wp_send_json_error(array('message' => 'Önerdiğiniz tarih ve saat sitede doludur veya ofis kapalıdır. Lütfen başka bir saat seçin.'));
        }

        $service_name = 'Danışmanlık Seansı';
        $duration = (int) $settings['slot_duration'];
        foreach ($settings['services'] as $srv) {
            if ($srv['id'] === $service_id) {
                $service_name = $srv['name'];
                $duration     = (int) $srv['duration'];
                break;
            }
        }

        $booking_code = 'RNV-' . strtoupper(wp_generate_password(4, false, false));

        // İlk müzakere günlüğü
        $initial_log = array(
            array(
                'time' => current_time('mysql'),
                'by'   => 'Danışan (' . $client_name . ')',
                'action' => 'Randevu talebi başlattı ve ilk tarih & saat teklifini sundu: ' . $date . ' ' . $time
            )
        );

        $wpdb->insert(
            $this->table_name,
            array(
                'booking_code'      => $booking_code,
                'client_name'       => $client_name,
                'client_phone'      => $client_phone,
                'client_email'      => $client_email,
                'voluntary_consent' => 1,
                'consultant_target' => $consultant_target,
                'service_id'        => $service_id,
                'service_name'      => $service_name,
                'session_mode'      => $session_mode,
                'appointment_date'  => $date,
                'appointment_time'  => $time,
                'duration_minutes'  => $duration,
                'client_notes'      => $notes,
                'proposed_by'       => 'Danışan',
                'client_ok'         => 1, // Danışan kendi önerdiği tarihi baştan kabul etmiş sayılır
                'consultant_ok'     => 0, // Danışman henüz onaylamadı
                'secretary_ok'      => 0, // Sekreter Hanım henüz onaylamadı
                'proposal_log'      => wp_json_encode($initial_log, JSON_UNESCAPED_UNICODE),
                'group_status'      => 'invited',
                'status'            => 'negotiating', // Müzakere aşamasında
                'emails_sent'       => 0,
                'created_at'        => current_time('mysql')
            )
        );

        $apt_id = $wpdb->insert_id;

        // WhatsApp grubuna ilk gönderilecek hazır koordinasyon mesajı
        $target_consultant_name = ($consultant_target == 2 && !empty($settings['consultant_2_name'])) ? $settings['consultant_2_name'] : $settings['consultant_name'];
        $group_intro_msg = sprintf(
            "Merhaba, ben %s.\nKendi irademle gruba katıldım ve sitemiz üzerinden randevu diyaloğunu başlattım.\n📅 *Önerdiğim Tarih & Saat:* %s saat %s\n🏷️ *Hizmet:* %s (%s)\n🔑 *Takip Kodu:* #%s\n👨‍⚕️ *Danışman:* %s\n🏢 *Ofis Sekreterya:* %s\n\nBu tarih ve saat sizler için de uygun mudur? Onayınızı veya karşı tarih önerinizi rica ederim.",
            $client_name,
            $date,
            $time,
            $service_name,
            $session_mode,
            $booking_code,
            $target_consultant_name,
            $settings['secretary_name']
        );

        wp_send_json_success(array(
            'id'                  => $apt_id,
            'booking_code'        => $booking_code,
            'client_name'         => $client_name,
            'client_phone'        => $client_phone,
            'appointment_date'    => $date,
            'appointment_time'    => $time,
            'service_name'        => $service_name,
            'session_mode'        => $session_mode,
            'whatsapp_group_link' => $settings['whatsapp_group_link'],
            'whatsapp_group_name' => $settings['whatsapp_group_name'],
            'group_intro_msg'     => $group_intro_msg,
            'status'              => 'negotiating',
            'client_ok'           => 1,
            'consultant_ok'       => 0,
            'secretary_ok'        => 0
        ));
    }

    /**
     * 2. ADIM: 3'LÜ MUTABAKAT & MÜZAKERE YANITI (Kabul Etme veya Yeni Tarih & Saat Önerme)
     */
    public function ajax_respond_proposal() {
        check_ajax_referer('orp_public_nonce', 'nonce');
        global $wpdb;

        $code        = isset($_POST['booking_code']) ? ltrim(strtoupper(sanitize_text_field($_POST['booking_code'])), '#') : '';
        $party       = isset($_POST['party']) ? sanitize_text_field($_POST['party']) : ''; // 'client', 'consultant', 'secretary'
        $action_type = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : 'approve'; // 'approve' veya 'counter_propose'
        $new_date    = isset($_POST['new_date']) ? sanitize_text_field($_POST['new_date']) : '';
        $new_time    = isset($_POST['new_time']) ? sanitize_text_field($_POST['new_time']) : '';

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE booking_code = %s", $code), ARRAY_A);
        if (!$row) {
            wp_send_json_error(array('message' => 'Randevu kaydı bulunamadı.'));
        }

        if ($row['status'] === 'approved') {
            wp_send_json_error(array('message' => 'Bu randevu için 3 taraf zaten mutabık kalmış ve gün/saat sabitlenmiştir.'));
        }

        $logs = !empty($row['proposal_log']) ? json_decode($row['proposal_log'], true) : array();
        if (!is_array($logs)) {
            $logs = array();
        }

        $party_labels = array(
            'client'     => 'Danışan (' . $row['client_name'] . ')',
            'consultant' => 'Danışman',
            'secretary'  => 'Sekreter Hanım'
        );
        $acting_label = isset($party_labels[$party]) ? $party_labels[$party] : 'Yetkili';

        $client_ok     = (int) $row['client_ok'];
        $consultant_ok = (int) $row['consultant_ok'];
        $secretary_ok  = (int) $row['secretary_ok'];
        $curr_date     = $row['appointment_date'];
        $curr_time     = $row['appointment_time'];
        $proposed_by   = $row['proposed_by'];

        if ($action_type === 'approve') {
            // İlgili taraf onayladı
            if ($party === 'client') {
                $client_ok = 1;
            } elseif ($party === 'consultant') {
                $consultant_ok = 1;
            } elseif ($party === 'secretary') {
                $secretary_ok = 1;
            }

            $logs[] = array(
                'time'   => current_time('mysql'),
                'by'     => $acting_label,
                'action' => 'Önerilen ' . $curr_date . ' ' . $curr_time . ' tarih ve saatini KABUL ETTİ.'
            );
        } elseif ($action_type === 'counter_propose') {
            // Birisi onaylamadı, yeni bir gün ve saat teklif etti
            if (empty($new_date) || empty($new_time)) {
                wp_send_json_error(array('message' => 'Lütfen alternatif tarih ve saati seçiniz.'));
            }

            // Yeni seçilen saat takvimde müsait mi kontrol et
            $day_info = $this->calculate_day_slots($new_date);
            $slot_free = false;
            foreach ($day_info['slots'] as $sl) {
                if ($sl['time'] === $new_time && $sl['available']) {
                    $slot_free = true;
                    break;
                }
            }
            if (!$slot_free) {
                wp_send_json_error(array('message' => 'Önerdiğiniz yeni gün ve saat sitede doludur veya çalışma saatleri dışındadır.'));
            }

            $curr_date = $new_date;
            $curr_time = $new_time;
            $proposed_by = $acting_label;

            // Teklifi yapan taraf onaylamış olur, diğer 2 tarafın onayı sıfırlanır!
            if ($party === 'client') {
                $client_ok = 1;
                $consultant_ok = 0;
                $secretary_ok = 0;
            } elseif ($party === 'consultant') {
                $consultant_ok = 1;
                $client_ok = 0;
                $secretary_ok = 0;
            } elseif ($party === 'secretary') {
                $secretary_ok = 1;
                $client_ok = 0;
                $consultant_ok = 0;
            }

            $logs[] = array(
                'time'   => current_time('mysql'),
                'by'     => $acting_label,
                'action' => 'Önceki saati kabul etmedi ve YENİ TARİH & SAAT ÖNERDİ: ' . $new_date . ' ' . $new_time
            );
        }

        // MUTABAKAT KONTROLÜ: 3 kişi mutabık kaldı mı?
        $all_mutabik = false;
        $settings = $this->get_settings();
        $secretary_active = !empty($settings['secretary_name']);

        if ($client_ok === 1 && $consultant_ok === 1 && (!$secretary_active || $secretary_ok === 1)) {
            $all_mutabik = true;
        }

        $new_status = $all_mutabik ? 'approved' : 'negotiating';

        $update_data = array(
            'appointment_date' => $curr_date,
            'appointment_time' => $curr_time,
            'proposed_by'      => $proposed_by,
            'client_ok'        => $client_ok,
            'consultant_ok'    => $consultant_ok,
            'secretary_ok'     => $secretary_ok,
            'proposal_log'     => wp_json_encode($logs, JSON_UNESCAPED_UNICODE),
            'status'           => $new_status
        );

        $wpdb->update($this->table_name, $update_data, array('id' => $row['id']));

        // Eğer 3 kişi mutabık kaldıysa:
        // 1. Sitede o gün ve saat kalıcı olarak sabitlendi (status = 'approved' olduğu için calculate_day_slots'ta kilitlendi)
        // 2. Danışmana ve sekretere otomatik onay e-postası gönderilir!
        if ($all_mutabik && empty($row['emails_sent'])) {
            $this->send_consensus_emails(array_merge($row, $update_data));
            $wpdb->update($this->table_name, array('emails_sent' => 1), array('id' => $row['id']));
        }

        wp_send_json_success(array(
            'booking_code'     => $code,
            'appointment_date' => $curr_date,
            'appointment_time' => $curr_time,
            'proposed_by'      => $proposed_by,
            'client_ok'        => $client_ok,
            'consultant_ok'    => $consultant_ok,
            'secretary_ok'     => $secretary_ok,
            'status'           => $new_status,
            'all_mutabik'      => $all_mutabik,
            'proposal_log'     => $logs
        ));
    }

    /**
     * 3 kişi mutabık kaldığında Danışman(lar)a ve Sekretere otomatik e-posta gönderir
     */
    public function send_consensus_emails($apt) {
        $settings = $this->get_settings();

        // Alıcılar: 1. Danışman, varsa 2. Danışman, varsa Sekreter Hanım
        $recipients = array();
        if (!empty($settings['consultant_email'])) {
            $recipients[] = $settings['consultant_email'];
        }
        if ($apt['consultant_target'] == 2 && !empty($settings['consultant_2_email'])) {
            $recipients[] = $settings['consultant_2_email'];
        }
        if (!empty($settings['secretary_email'])) {
            $recipients[] = $settings['secretary_email'];
        }

        if (empty($recipients)) {
            return false;
        }

        $subject = sprintf(
            '[%s] Randevu Kesinleşti & Sabitlendi: %s - %s %s',
            $settings['office_name'],
            $apt['client_name'],
            $apt['appointment_date'],
            $apt['appointment_time']
        );

        $body = "Sayın Yetkili,\n\n";
        $body .= "Danışan, Danışman ve Sekreter Hanım arasında yürütülen 3'lü mutabakat süreci başarıyla tamamlanmış ve randevu KESİNLEŞMİŞTİR.\n";
        $body .= "Bu gün ve saat web sitenizdeki takvimde otomatik olarak kilitlenmiş ve diğer danışanlara kapatılmıştır.\n\n";
        $body .= "----------------------------------------\n";
        $body .= "Takip Kodu        : #" . $apt['booking_code'] . "\n";
        $body .= "Danışan Adı Soyadı: " . $apt['client_name'] . "\n";
        $body .= "WhatsApp Telefonu : " . $apt['client_phone'] . "\n";
        if (!empty($apt['client_email'])) {
            $body .= "Danışan E-postası : " . $apt['client_email'] . "\n";
        }
        $body .= "Hizmet Türü       : " . $apt['service_name'] . " (" . $apt['session_mode'] . ")\n";
        $body .= "Sabitlenen Tarih  : " . $apt['appointment_date'] . "\n";
        $body .= "Sabitlenen Saat   : " . $apt['appointment_time'] . "\n";
        $body .= "Ofis Sekreteryası : " . $settings['secretary_name'] . "\n";
        $body .= "WhatsApp Grubu    : " . $settings['whatsapp_group_name'] . "\n";
        if (!empty($apt['client_notes'])) {
            $body .= "Danışan Notu      : " . $apt['client_notes'] . "\n";
        }
        $body .= "----------------------------------------\n\n";
        $body .= "İyi çalışmalar dileriz.\n" . get_bloginfo('name');

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        wp_mail($recipients, $subject, $body, $headers);

        // Danışanın e-postası varsa ona da onay özeti yolla
        if (!empty($apt['client_email'])) {
            $client_subject = 'Randevunuz Kesinleşti: ' . $apt['appointment_date'] . ' ' . $apt['appointment_time'];
            $client_body = "Merhaba Sayın " . $apt['client_name'] . ",\n\n";
            $client_body .= "Danışmanınız ve Ofis Sekreteryası ile yürütülen mutabakat sonucunda randevunuz onaylanmış ve takvimde adınıza sabitlenmiştir.\n\n";
            $client_body .= "Tarih: " . $apt['appointment_date'] . "\nSaat: " . $apt['appointment_time'] . "\nHizmet: " . $apt['service_name'] . "\nTakip Kodu: #" . $apt['booking_code'] . "\n\n";
            $client_body .= "Görüşmek dileğiyle,\n" . $settings['office_name'];
            wp_mail($apt['client_email'], $client_subject, $client_body, $headers);
        }

        return true;
    }

    /**
     * AJAX: Takip kodu ile sorgulama
     */
    public function ajax_lookup_appointment() {
        check_ajax_referer('orp_public_nonce', 'nonce');
        global $wpdb;
        $code = isset($_POST['booking_code']) ? ltrim(strtoupper(sanitize_text_field($_POST['booking_code'])), '#') : '';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE booking_code = %s", $code), ARRAY_A);
        if (!$row) {
            wp_send_json_error(array('message' => 'Bu koda ait randevu bulunamadı.'));
        }
        if (!empty($row['proposal_log'])) {
            $row['proposal_log_decoded'] = json_decode($row['proposal_log'], true);
        } else {
            $row['proposal_log_decoded'] = array();
        }
        wp_send_json_success($row);
    }

    public function register_admin_menu() {
        add_menu_page(
            '3\'lü Mutabakatlı Randevu',
            'Randevu & Mutabakat',
            'manage_options',
            'otomatik-randevu',
            array($this, 'render_admin_page'),
            'dashicons-groups',
            26
        );
    }

    /**
     * Admin Panel İşlemleri
     */
    public function handle_admin_actions() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        global $wpdb;

        if (isset($_GET['page'], $_GET['orp_action'], $_GET['id']) && $_GET['page'] === 'otomatik-randevu') {
            check_admin_referer('orp_admin_action');
            $id  = (int) $_GET['id'];
            $act = sanitize_text_field($_GET['orp_action']);
            $apt = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id), ARRAY_A);

            if ($apt) {
                if ($act === 'approve_as_consultant') {
                    // Danışman adına onayla
                    $logs = !empty($apt['proposal_log']) ? json_decode($apt['proposal_log'], true) : array();
                    $logs[] = array('time' => current_time('mysql'), 'by' => 'Danışman', 'action' => 'Yönetim panelinden tarihi onayladı.');
                    $c_ok = 1;
                    $s_ok = (int)$apt['secretary_ok'];
                    $cl_ok = (int)$apt['client_ok'];
                    $mutabik = ($cl_ok === 1 && $c_ok === 1 && $s_ok === 1);
                    $new_status = $mutabik ? 'approved' : 'negotiating';
                    $wpdb->update($this->table_name, array(
                        'consultant_ok' => 1,
                        'proposal_log'  => wp_json_encode($logs, JSON_UNESCAPED_UNICODE),
                        'status'        => $new_status
                    ), array('id' => $id));
                    if ($mutabik && empty($apt['emails_sent'])) {
                        $this->send_consensus_emails(array_merge($apt, array('consultant_ok' => 1, 'status' => 'approved')));
                        $wpdb->update($this->table_name, array('emails_sent' => 1), array('id' => $id));
                    }
                } elseif ($act === 'approve_as_secretary') {
                    // Sekreter Hanım adına onayla
                    $logs = !empty($apt['proposal_log']) ? json_decode($apt['proposal_log'], true) : array();
                    $logs[] = array('time' => current_time('mysql'), 'by' => 'Sekreter Hanım', 'action' => 'Yönetim panelinden tarihi onayladı.');
                    $s_ok = 1;
                    $c_ok = (int)$apt['consultant_ok'];
                    $cl_ok = (int)$apt['client_ok'];
                    $mutabik = ($cl_ok === 1 && $c_ok === 1 && $s_ok === 1);
                    $new_status = $mutabik ? 'approved' : 'negotiating';
                    $wpdb->update($this->table_name, array(
                        'secretary_ok' => 1,
                        'proposal_log' => wp_json_encode($logs, JSON_UNESCAPED_UNICODE),
                        'status'       => $new_status
                    ), array('id' => $id));
                    if ($mutabik && empty($apt['emails_sent'])) {
                        $this->send_consensus_emails(array_merge($apt, array('secretary_ok' => 1, 'status' => 'approved')));
                        $wpdb->update($this->table_name, array('emails_sent' => 1), array('id' => $id));
                    }
                } elseif ($act === 'cancel') {
                    $wpdb->update($this->table_name, array('status' => 'cancelled'), array('id' => $id));
                } elseif ($act === 'delete') {
                    $wpdb->delete($this->table_name, array('id' => $id));
                }
            }
            wp_redirect(admin_url('admin.php?page=otomatik-randevu&updated=1'));
            exit;
        }

        // Ayarları kaydet
        if (isset($_POST['orp_save_settings'])) {
            check_admin_referer('orp_settings_verify');
            $settings = $this->get_settings();

            $settings['consultant_name']    = sanitize_text_field($_POST['consultant_name']);
            $settings['consultant_phone']   = sanitize_text_field($_POST['consultant_phone']);
            $settings['consultant_email']   = sanitize_email($_POST['consultant_email']);
            $settings['consultant_2_name']  = sanitize_text_field($_POST['consultant_2_name']);
            $settings['consultant_2_phone'] = sanitize_text_field($_POST['consultant_2_phone']);
            $settings['consultant_2_email'] = sanitize_email($_POST['consultant_2_email']);
            $settings['secretary_name']     = sanitize_text_field($_POST['secretary_name']);
            $settings['secretary_phone']    = sanitize_text_field($_POST['secretary_phone']);
            $settings['secretary_email']    = sanitize_email($_POST['secretary_email']);
            $settings['office_name']        = sanitize_text_field($_POST['office_name']);
            $settings['whatsapp_group_name']= sanitize_text_field($_POST['whatsapp_group_name']);
            $settings['whatsapp_group_link']= esc_url_raw($_POST['whatsapp_group_link']);
            $settings['slot_duration']      = max(15, (int) $_POST['slot_duration']);
            $settings['work_start']         = sanitize_text_field($_POST['work_start']);
            $settings['work_end']           = sanitize_text_field($_POST['work_end']);
            $settings['voluntary_text']     = sanitize_textarea_field($_POST['voluntary_text']);

            update_option($this->option_key, $settings);
            wp_redirect(admin_url('admin.php?page=otomatik-randevu&tab=settings&saved=1'));
            exit;
        }
    }

    /**
     * Kısa Kod: [otomatik_randevu] — Sitede 3 Adımlı Etkileşimli Panel
     */
    public function render_booking_shortcode($atts) {
        $settings = $this->get_settings();
        $nonce    = wp_create_nonce('orp_public_nonce');
        $ajax_url = admin_url('admin-ajax.php');

        ob_start();
        ?>
        <div id="orp-booking-app" class="orp-wrapper">
            <style>
                .orp-wrapper { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; max-width: 780px; margin: 20px auto; background: #ffffff; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); overflow: hidden; border: 1px solid #e2e8f0; }
                .orp-header { background: linear-gradient(135deg, #075e54, #128c7e); color: #fff; padding: 24px; text-align: center; }
                .orp-header h2 { margin: 0 0 8px; font-size: 22px; font-weight: 700; color: #fff; }
                .orp-header p { margin: 0; opacity: 0.9; font-size: 14px; }
                .orp-tabs { display: flex; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
                .orp-tab-btn { flex: 1; padding: 14px; text-align: center; background: none; border: none; font-size: 14px; font-weight: 600; color: #64748b; cursor: pointer; transition: all 0.2s; }
                .orp-tab-btn.active { color: #0f766e; border-bottom: 3px solid #0f766e; background: #fff; }
                .orp-content { padding: 24px; }
                .orp-pane { display: none; }
                .orp-pane.active { display: block; }
                .orp-form-group { margin-bottom: 18px; }
                .orp-form-group label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #334155; }
                .orp-form-control { width: 100%; padding: 11px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14px; box-sizing: border-box; transition: border-color 0.2s; }
                .orp-form-control:focus { outline: none; border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15,118,110,0.15); }
                .orp-voluntary-box { background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 10px; padding: 16px; margin: 20px 0; }
                .orp-voluntary-box h4 { margin: 0 0 8px; font-size: 14px; color: #166534; font-weight: 700; display: flex; align-items: center; gap: 6px; }
                .orp-voluntary-box p { margin: 0 0 12px; font-size: 13px; color: #15803d; line-height: 1.5; }
                .orp-chk-label { display: flex; align-items: flex-start; gap: 10px; font-size: 13px; font-weight: 600; color: #14532d; cursor: pointer; }
                .orp-chk-label input { margin-top: 3px; }
                .orp-slots-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(85px, 1fr)); gap: 8px; margin-top: 8px; }
                .orp-slot-pill { padding: 8px 6px; text-align: center; border: 1.5px solid #cbd5e1; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; background: #fff; color: #1e293b; transition: all 0.15s; }
                .orp-slot-pill:hover:not(.booked) { border-color: #0f766e; background: #f0fdfa; color: #0f766e; }
                .orp-slot-pill.selected { border-color: #0f766e; background: #0f766e; color: #fff; }
                .orp-slot-pill.booked { background: #f1f5f9; border-color: #e2e8f0; color: #94a3b8; cursor: not-allowed; text-decoration: line-through; }
                .orp-btn-submit { display: block; width: 100%; padding: 14px; background: #25d366; color: #fff; border: none; border-radius: 10px; font-size: 15px; font-weight: 700; cursor: pointer; transition: background 0.2s; box-shadow: 0 4px 12px rgba(37,211,102,0.3); }
                .orp-btn-submit:hover { background: #1ebc59; }
                .orp-party-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 14px; background: #fafafa; }
                .orp-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
                .orp-badge-ok { background: #dcfce7; color: #15803d; }
                .orp-badge-wait { background: #fef9c3; color: #854d0e; }
                .orp-badge-final { background: #15803d; color: #fff; }
                .orp-timeline-item { padding: 10px 14px; border-left: 3px solid #0f766e; background: #f8fafc; margin-bottom: 8px; border-radius: 0 8px 8px 0; font-size: 13px; }
            </style>

            <div class="orp-header">
                <h2>🤝 Randevu & 3'lü Koordinasyon Masası</h2>
                <p>Danışan • Danışman • Sekreter Hanım Arasında Mutabakat Sistemi</p>
            </div>

            <div class="orp-tabs">
                <button type="button" class="orp-tab-btn active" onclick="orpSwitchTab('new')">1️⃣ Randevu Başlat & İrade Onayı</button>
                <button type="button" class="orp-tab-btn" onclick="orpSwitchTab('track')">2️⃣ 3'lü Mutabakat Takibi</button>
            </div>

            <div class="orp-content">
                <!-- 1. SEKME: Randevu Başlat -->
                <div id="orp-pane-new" class="orp-pane active">
                    <form id="orp-booking-form" onsubmit="orpSubmitBooking(event)">
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
                            <div class="orp-form-group">
                                <label>Adınız Soyadınız *</label>
                                <input type="text" name="client_name" class="orp-form-control" placeholder="Ad Soyad" required />
                            </div>
                            <div class="orp-form-group">
                                <label>WhatsApp Telefon Numaranız *</label>
                                <input type="tel" name="client_phone" class="orp-form-control" placeholder="05XX XXX XX XX" required />
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
                            <div class="orp-form-group">
                                <label>E-posta Adresiniz (Onay Bildirimi İçin)</label>
                                <input type="email" name="client_email" class="orp-form-control" placeholder="ornek@eposta.com" />
                            </div>
                            <div class="orp-form-group">
                                <label>Görüşülecek Danışman</label>
                                <select name="consultant_target" class="orp-form-control">
                                    <option value="1"><?php echo esc_html($settings['consultant_name']); ?></option>
                                    <?php if (!empty($settings['consultant_2_name'])): ?>
                                        <option value="2"><?php echo esc_html($settings['consultant_2_name']); ?> (Ortak Danışman)</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
                            <div class="orp-form-group">
                                <label>Hizmet Türü</label>
                                <select name="service_id" class="orp-form-control">
                                    <?php foreach ($settings['services'] as $srv): ?>
                                        <option value="<?php echo esc_attr($srv['id']); ?>"><?php echo esc_html($srv['name']); ?> (<?php echo esc_html($srv['duration']); ?> dk)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="orp-form-group">
                                <label>Görüşme Şekli</label>
                                <select name="session_mode" class="orp-form-control">
                                    <option value="Ofiste Yüz Yüze">Ofiste Yüz Yüze (Sekreterya Karşılamalı)</option>
                                    <option value="Online Görüşme">Online Görüşme (Görüntülü)</option>
                                </select>
                            </div>
                        </div>

                        <div class="orp-form-group">
                            <label>Önermek İstediğiniz Gün *</label>
                            <input type="date" id="orp-date-picker" name="appointment_date" class="orp-form-control" min="<?php echo esc_attr(date('Y-m-d')); ?>" value="<?php echo esc_attr(date('Y-m-d', strtotime('+1 day'))); ?>" onchange="orpLoadSlots(this.value)" required />
                        </div>

                        <div class="orp-form-group">
                            <label>Önermek İstediğiniz Saat * <span style="font-weight:normal; color:#64748b;">(Sitede kilitli olan saatler seçilemez)</span></label>
                            <div id="orp-slots-container" class="orp-slots-grid">
                                <em>Tarih seçiliyor...</em>
                            </div>
                            <input type="hidden" name="appointment_time" id="orp-selected-time" required />
                        </div>

                        <div class="orp-form-group">
                            <label>Not veya Belirtmek İstedikleriniz (İsteğe Bağlı)</label>
                            <textarea name="client_notes" class="orp-form-control" rows="2" placeholder="Örn: Hafta içi öğleden sonra daha uygunum..."></textarea>
                        </div>

                        <!-- ZORUNLU İRADE BEYANI -->
                        <div class="orp-voluntary-box">
                            <h4>📋 Kendi İradesiyle Gruba Katılım Beyanı & Onayı</h4>
                            <p><?php echo esc_html($settings['voluntary_text']); ?></p>
                            <label class="orp-chk-label">
                                <input type="checkbox" name="voluntary_consent" value="1" required />
                                <span>Yukarıdaki hatırlatmayı okudum, kendi özgür irademle koordinasyon WhatsApp grubuna katılacağımı ve 3'lü mutabakat sürecini kabul ettiğimi onaylıyorum. *</span>
                            </label>
                        </div>

                        <button type="submit" id="orp-btn-submit" class="orp-btn-submit">
                            🚀 Randevu Diyaloğunu Başlat & Gruba Katıl
                        </button>
                    </form>

                    <!-- Başarı / WhatsApp Grubu Yönlendirme Kartı -->
                    <div id="orp-result-card" style="display:none; margin-top:20px; background:#f0fdf4; border:2px solid #22c55e; border-radius:12px; padding:20px; text-align:center;">
                        <h3 style="color:#15803d; margin:0 0 10px;">🎉 Randevu Diyaloğunuz Başlatıldı!</h3>
                        <p style="font-size:14px; color:#166534; margin-bottom:15px;">
                            Takip Kodunuz: <strong id="orp-res-code" style="background:#bbf7d0; padding:3px 8px; border-radius:6px; font-size:16px;"></strong>
                        </p>
                        <p style="font-size:13px; color:#14532d; line-height:1.5; margin-bottom:15px;">
                            Önerdiğiniz tarih ve saat: <strong id="orp-res-datetime"></strong>.<br />
                            Şimdi aşağıdaki butona tıklayarak WhatsApp grubuna otomatik katılabilir ve mesajınızı gönderebilirsiniz:
                        </p>
                        <a id="orp-res-wa-link" href="#" target="_blank" style="display:inline-block; background:#25d366; color:#fff; text-decoration:none; font-weight:700; font-size:15px; padding:12px 24px; border-radius:8px; box-shadow:0 4px 12px rgba(37,211,102,0.3);">
                            💬 WhatsApp Grubuna Otomatik Gir
                        </a>
                        <div style="margin-top:15px; text-align:left; background:#fff; border:1px solid #cbd5e1; border-radius:8px; padding:12px;">
                            <label style="font-size:12px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Gruba Gönderilecek Hazır Mutabakat Mesajı:</label>
                            <textarea id="orp-res-msg" style="width:100%; height:90px; font-size:12px; border:none; resize:none; background:transparent;" readonly></textarea>
                        </div>
                    </div>
                </div>

                <!-- 2. SEKME: 3'lü Mutabakat Takibi -->
                <div id="orp-pane-track" class="orp-pane">
                    <div style="display:flex; gap:10px; margin-bottom:20px;">
                        <input type="text" id="orp-track-code" class="orp-form-control" placeholder="Randevu Takip Kodunuzu Girin (Örn: #RNV-XXXX)" />
                        <button type="button" class="orp-btn-submit" style="width:auto; white-space:nowrap; padding:11px 20px;" onclick="orpTrackLookup()">Sorgula</button>
                    </div>

                    <div id="orp-track-details" style="display:none;">
                        <!-- Durum Özeti -->
                        <div style="background:#f8fafc; border:1.5px solid #cbd5e1; border-radius:12px; padding:18px; margin-bottom:20px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                <h3 id="orp-trk-title" style="margin:0; font-size:16px; color:#0f172a;"></h3>
                                <span id="orp-trk-status-badge" class="orp-badge"></span>
                            </div>
                            <p style="margin:4px 0; font-size:14px;"><strong>Mevcut Önerilen Tarih & Saat:</strong> <span id="orp-trk-current-time" style="color:#0f766e; font-weight:700;"></span></p>
                            <p style="margin:4px 0; font-size:13px; color:#64748b;">Son Öneriyi Yapan: <strong id="orp-trk-proposed-by"></strong></p>
                        </div>

                        <!-- 3 Tarafın Onay Kartları -->
                        <h4 style="margin:0 0 10px; font-size:14px; color:#334155;">3 Tarafın Mutabakat Durumu:</h4>
                        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px; margin-bottom:20px;">
                            <div class="orp-party-card" style="text-align:center;">
                                <div style="font-size:22px; margin-bottom:6px;">👤</div>
                                <div style="font-weight:700; font-size:13px;" id="orp-trk-client-label">Danışan</div>
                                <div style="margin-top:6px;" id="orp-trk-client-badge"></div>
                            </div>
                            <div class="orp-party-card" style="text-align:center;">
                                <div style="font-size:22px; margin-bottom:6px;">👨‍⚕️</div>
                                <div style="font-weight:700; font-size:13px;">Danışman</div>
                                <div style="margin-top:6px;" id="orp-trk-consultant-badge"></div>
                            </div>
                            <div class="orp-party-card" style="text-align:center;">
                                <div style="font-size:22px; margin-bottom:6px;">🏢</div>
                                <div style="font-weight:700; font-size:13px;">Sekreter Hanım</div>
                                <div style="margin-top:6px;" id="orp-trk-secretary-badge"></div>
                            </div>
                        </div>

                        <!-- Müzakere & Karşı Öneri Formu -->
                        <div id="orp-action-box" style="background:#fff; border:1.5px solid #0f766e; border-radius:12px; padding:18px; margin-bottom:20px;">
                            <h4 style="margin:0 0 12px; font-size:14px; color:#0f766e;">✍️ Bu Tarih İçin Kararınız veya Yeni Tarih Öneriniz:</h4>
                            <div style="display:flex; gap:10px; margin-bottom:12px;">
                                <select id="orp-acting-party" class="orp-form-control" style="flex:1;">
                                    <option value="client">Ben Danışanım</option>
                                    <option value="consultant">Ben Danışmanım</option>
                                    <option value="secretary">Ben Sekreter Hanımım</option>
                                </select>
                                <button type="button" class="orp-btn-submit" style="width:auto; padding:11px 24px; background:#16a34a;" onclick="orpSendResponse('approve')">
                                    ✅ Tarihi & Saati Kabul Ediyorum
                                </button>
                            </div>

                            <div style="border-top:1px dashed #cbd5e1; padding-top:12px; margin-top:12px;">
                                <label style="display:block; font-size:13px; font-weight:600; color:#b91c1c; margin-bottom:6px;">
                                    Tarih/Saat Uygun Değilse Başka Gün ve Saat Öner (Diğerleri onaylayana kadar döngü sürer):
                                </label>
                                <div style="display:flex; gap:10px;">
                                    <input type="date" id="orp-new-date" class="orp-form-control" style="flex:1;" />
                                    <input type="time" id="orp-new-time" class="orp-form-control" style="flex:1;" />
                                    <button type="button" class="orp-btn-submit" style="width:auto; padding:11px 20px; background:#ea580c;" onclick="orpSendResponse('counter_propose')">
                                        🔄 Yeni Tarih/Saat Öner
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Müzakere Günlüğü -->
                        <h4 style="margin:0 0 10px; font-size:14px; color:#334155;">📜 Mutabakat ve Müzakere Geçmişi:</h4>
                        <div id="orp-trk-timeline"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            var orpAjaxUrl = "<?php echo esc_url($ajax_url); ?>";
            var orpNonce   = "<?php echo esc_js($nonce); ?>";
            var orpCurrentCode = "";

            function orpSwitchTab(tab) {
                document.querySelectorAll('.orp-tab-btn').forEach(function(b){ b.classList.remove('active'); });
                document.querySelectorAll('.orp-pane').forEach(function(p){ p.classList.remove('active'); });
                if(tab === 'new') {
                    document.querySelectorAll('.orp-tab-btn')[0].classList.add('active');
                    document.getElementById('orp-pane-new').classList.add('active');
                } else {
                    document.querySelectorAll('.orp-tab-btn')[1].classList.add('active');
                    document.getElementById('orp-pane-track').classList.add('active');
                }
            }

            function orpLoadSlots(dateStr) {
                var container = document.getElementById('orp-slots-container');
                container.innerHTML = "<em>Saatler yükleniyor...</em>";
                fetch(orpAjaxUrl + "?action=orp_get_slots&date=" + encodeURIComponent(dateStr))
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if(!res.success || !res.data.available) {
                            container.innerHTML = "<div style='grid-column:1/-1; color:#dc2626; font-size:13px;'>Bu tarihte ofis kapalı veya müsait saat yok.</div>";
                            document.getElementById('orp-selected-time').value = "";
                            return;
                        }
                        var html = "";
                        res.data.slots.forEach(function(s){
                            if(s.available) {
                                html += "<div class='orp-slot-pill' onclick='orpSelectTime(this, \"" + s.time + "\")'>" + s.time + "</div>";
                            } else {
                                html += "<div class='orp-slot-pill booked' title='Bu saat doludur / kilitlidir'>" + s.time + "</div>";
                            }
                        });
                        container.innerHTML = html;
                        document.getElementById('orp-selected-time').value = "";
                    });
            }

            function orpSelectTime(el, timeStr) {
                document.querySelectorAll('.orp-slot-pill').forEach(function(p){ p.classList.remove('selected'); });
                el.classList.add('selected');
                document.getElementById('orp-selected-time').value = timeStr;
            }

            function orpSubmitBooking(e) {
                e.preventDefault();
                var time = document.getElementById('orp-selected-time').value;
                if(!time) {
                    alert('Lütfen müsait saatlerden birini seçin.');
                    return;
                }
                var form = document.getElementById('orp-booking-form');
                var formData = new FormData(form);
                formData.append('action', 'orp_create_appointment');
                formData.append('nonce', orpNonce);

                var btn = document.getElementById('orp-btn-submit');
                btn.disabled = true;
                btn.innerText = "İşleniyor...";

                fetch(orpAjaxUrl, { method: 'POST', body: formData })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        btn.disabled = false;
                        btn.innerText = "🚀 Randevu Diyaloğunu Başlat & Gruba Katıl";
                        if(!res.success) {
                            alert(res.data.message || 'Hata oluştu.');
                            return;
                        }
                        var data = res.data;
                        orpCurrentCode = data.booking_code;
                        document.getElementById('orp-res-code').innerText = "#" + data.booking_code;
                        document.getElementById('orp-res-datetime').innerText = data.appointment_date + " saat " + data.appointment_time;
                        document.getElementById('orp-res-msg').value = data.group_intro_msg;
                        document.getElementById('orp-res-wa-link').href = data.whatsapp_group_link;
                        document.getElementById('orp-result-card').style.display = 'block';
                        form.reset();
                        orpLoadSlots(document.getElementById('orp-date-picker').value);
                    });
            }

            function orpTrackLookup() {
                var code = document.getElementById('orp-track-code').value.trim();
                if(!code) { alert('Lütfen takip kodunuzu girin.'); return; }
                orpCurrentCode = code;
                var fd = new FormData();
                fd.append('action', 'orp_lookup_appointment');
                fd.append('nonce', orpNonce);
                fd.append('booking_code', code);

                fetch(orpAjaxUrl, { method:'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if(!res.success) {
                            alert(res.data.message || 'Randevu bulunamadı.');
                            return;
                        }
                        orpRenderTrackData(res.data);
                    });
            }

            function orpRenderTrackData(row) {
                document.getElementById('orp-track-details').style.display = 'block';
                document.getElementById('orp-trk-title').innerText = "#" + row.booking_code + " — " + row.client_name;
                document.getElementById('orp-trk-current-time').innerText = row.appointment_date + " saat " + row.appointment_time;
                document.getElementById('orp-trk-proposed-by').innerText = row.proposed_by;

                var badge = document.getElementById('orp-trk-status-badge');
                if(row.status === 'approved') {
                    badge.className = 'orp-badge orp-badge-final';
                    badge.innerText = '🔒 3 Taraf Mutabık — Sitede Kapatıldı & Onaylandı';
                    document.getElementById('orp-action-box').style.display = 'none';
                } else {
                    badge.className = 'orp-badge orp-badge-wait';
                    badge.innerText = '⏳ 3\'lü Mutabakat Sürüyor';
                    document.getElementById('orp-action-box').style.display = 'block';
                }

                // Rozetler
                document.getElementById('orp-trk-client-badge').innerHTML = row.client_ok == 1 ? '<span class="orp-badge orp-badge-ok">✅ Kabul Etti</span>' : '<span class="orp-badge orp-badge-wait">⏳ Bekliyor</span>';
                document.getElementById('orp-trk-consultant-badge').innerHTML = row.consultant_ok == 1 ? '<span class="orp-badge orp-badge-ok">✅ Kabul Etti</span>' : '<span class="orp-badge orp-badge-wait">⏳ Bekliyor</span>';
                document.getElementById('orp-trk-secretary-badge').innerHTML = row.secretary_ok == 1 ? '<span class="orp-badge orp-badge-ok">✅ Kabul Etti</span>' : '<span class="orp-badge orp-badge-wait">⏳ Bekliyor</span>';

                // Zaman çizelgesi
                var timelineHtml = "";
                var logs = row.proposal_log_decoded || [];
                logs.slice().reverse().forEach(function(l){
                    timelineHtml += "<div class='orp-timeline-item'><strong>" + l.by + ":</strong> " + l.action + " <span style='color:#94a3b8; font-size:11px; float:right;'>" + l.time + "</span></div>";
                });
                document.getElementById('orp-trk-timeline').innerHTML = timelineHtml || "<em>Henüz kayıt yok.</em>";
            }

            function orpSendResponse(actionType) {
                if(!orpCurrentCode) { alert('Lütfen önce takip kodunu sorgulayın.'); return; }
                var party = document.getElementById('orp-acting-party').value;
                var fd = new FormData();
                fd.append('action', 'orp_respond_proposal');
                fd.append('nonce', orpNonce);
                fd.append('booking_code', orpCurrentCode);
                fd.append('party', party);
                fd.append('action_type', actionType);

                if(actionType === 'counter_propose') {
                    var d = document.getElementById('orp-new-date').value;
                    var t = document.getElementById('orp-new-time').value;
                    if(!d || !t) { alert('Lütfen yeni tarih ve saati seçin.'); return; }
                    fd.append('new_date', d);
                    fd.append('new_time', t);
                }

                fetch(orpAjaxUrl, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if(!res.success) {
                            alert(res.data.message || 'İşlem gerçekleştirilemedi.');
                            return;
                        }
                        alert(actionType === 'approve' ? 'Onayınız kaydedildi!' : 'Yeni tarih/saat öneriniz kaydedildi ve diğer tarafların onayına sunuldu.');
                        orpTrackLookup();
                    });
            }

            // Sayfa açıldığında yarının slotlarını getir
            document.addEventListener("DOMContentLoaded", function(){
                var dp = document.getElementById('orp-date-picker');
                if(dp) { orpLoadSlots(dp.value); }
            });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * WP Admin Paneli: Randevular, 3'lü Mutabakat Takibi ve Ayarlar
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'appointments';
        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1>🤝 Otomatik Randevu & 3'lü Mutabakat Masası</h1>
            <h2 class="nav-tab-wrapper">
                <a href="?page=otomatik-randevu&tab=appointments" class="nav-tab <?php echo $tab === 'appointments' ? 'nav-tab-active' : ''; ?>">Randevular & Mutabakat Durumu</a>
                <a href="?page=otomatik-randevu&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">Danışman, Sekreterya & E-posta Ayarları</a>
            </h2>

            <?php if ($tab === 'appointments'): ?>
                <?php
                $rows = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY id DESC LIMIT 100", ARRAY_A);
                ?>
                <p>Sayfalarınıza veya yazılarınıza <code>[otomatik_randevu]</code> kısa kodunu ekleyerek danışanların bu formu doldurmasını ve 3'lü mutabakat sürecine girmesini sağlayabilirsiniz.</p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:100px;">Kod</th>
                            <th>Danışan</th>
                            <th>İrade Onayı</th>
                            <th>Tarih & Saat</th>
                            <th>3'lü Mutabakat Durumu</th>
                            <th>Son Durum</th>
                            <th>Yönetim Aksiyonu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="7">Henüz randevu talebi bulunmuyor.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td><strong>#<?php echo esc_html($r['booking_code']); ?></strong></td>
                                <td>
                                    <strong><?php echo esc_html($r['client_name']); ?></strong><br />
                                    <small><?php echo esc_html($r['client_phone']); ?></small><br />
                                    <small><?php echo esc_html($r['service_name']); ?></small>
                                </td>
                                <td>
                                    <?php echo !empty($r['voluntary_consent']) ? '<span style="color:#15803d; font-weight:700;">✅ İradesiyle Onayladı</span>' : '<span style="color:#b91c1c;">❌ Onaysız</span>'; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($r['appointment_date']); ?></strong><br />
                                    <?php echo esc_html($r['appointment_time']); ?>
                                </td>
                                <td>
                                    <span title="Danışan"><?php echo $r['client_ok'] ? '👤 Danışan: ✅' : '👤 Danışan: ⏳'; ?></span><br />
                                    <span title="Danışman"><?php echo $r['consultant_ok'] ? '👨‍⚕️ Danışman: ✅' : '👨‍⚕️ Danışman: ⏳'; ?></span><br />
                                    <span title="Sekreter Hanım"><?php echo $r['secretary_ok'] ? '🏢 Sekreter: ✅' : '🏢 Sekreter: ⏳'; ?></span>
                                </td>
                                <td>
                                    <?php if ($r['status'] === 'approved'): ?>
                                        <span class="button button-small" style="background:#15803d; color:#fff; border:none;">🔒 Sabitlendi / Dolu</span>
                                    <?php elseif ($r['status'] === 'negotiating'): ?>
                                        <span class="button button-small" style="background:#eab308; color:#000; border:none;">⏳ Müzakere Ediliyor</span>
                                    <?php else: ?>
                                        <span class="button button-small"><?php echo esc_html($r['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r['status'] !== 'approved'): ?>
                                        <?php if (!$r['consultant_ok']): ?>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=otomatik-randevu&orp_action=approve_as_consultant&id=' . $r['id']), 'orp_admin_action'); ?>" class="button button-small button-primary">Danışman Olarak Onayla</a>
                                        <?php endif; ?>
                                        <?php if (!$r['secretary_ok']): ?>
                                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=otomatik-randevu&orp_action=approve_as_secretary&id=' . $r['id']), 'orp_admin_action'); ?>" class="button button-small">Sekreter Olarak Onayla</a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <small style="color:#15803d;">Tüm taraflar mutabık. E-postalar yollandı.</small>
                                    <?php endif; ?>
                                    <br />
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=otomatik-randevu&orp_action=cancel&id=' . $r['id']), 'orp_admin_action'); ?>" style="color:#b91c1c; font-size:11px;">İptal Et</a> |
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=otomatik-randevu&orp_action=delete&id=' . $r['id']), 'orp_admin_action'); ?>" style="color:#b91c1c; font-size:11px;" onclick="return confirm('Silmek istediğinize emin misiniz?');">Sil</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

            <?php elseif ($tab === 'settings'): ?>
                <form method="post" action="">
                    <?php wp_nonce_field('orp_settings_verify'); ?>
                    <h3>👨‍⚕️ Danışmanlar & Ofis Sekreteryası</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">1. Danışman Adı</th>
                            <td><input type="text" name="consultant_name" value="<?php echo esc_attr($settings['consultant_name']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row">1. Danışman WhatsApp Telefonu</th>
                            <td><input type="text" name="consultant_phone" value="<?php echo esc_attr($settings['consultant_phone']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row">1. Danışman E-posta Adresi (Bildirim İçin)</th>
                            <td><input type="email" name="consultant_email" value="<?php echo esc_attr($settings['consultant_email']); ?>" class="regular-text" /><p class="description">Mutabakat sağlandığında kesinleşen randevu bilgisi bu adrese otomatik iletilir.</p></td>
                        </tr>
                        <tr>
                            <th scope="row">2. Danışman / Ortak Adı (İsteğe Bağlı)</th>
                            <td><input type="text" name="consultant_2_name" value="<?php echo esc_attr($settings['consultant_2_name']); ?>" class="regular-text" /><p class="description">İleride bir ortak alırsanız burayı doldurabilirsiniz.</p></td>
                        </tr>
                        <tr>
                            <th scope="row">2. Danışman / Ortak WhatsApp Telefonu</th>
                            <td><input type="text" name="consultant_2_phone" value="<?php echo esc_attr($settings['consultant_2_phone']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row">2. Danışman / Ortak E-posta Adresi</th>
                            <td><input type="email" name="consultant_2_email" value="<?php echo esc_attr($settings['consultant_2_email']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row">Ofis Sekreteri Adı</th>
                            <td><input type="text" name="secretary_name" value="<?php echo esc_attr($settings['secretary_name']); ?>" class="regular-text" /><p class="description">Farklı bir ofise geçtiğinizde sadece buradaki adı, telefonu ve e-postayı değiştirmeniz yeterlidir.</p></td>
                        </tr>
                        <tr>
                            <th scope="row">Ofis Sekreteri WhatsApp Telefonu</th>
                            <td><input type="text" name="secretary_phone" value="<?php echo esc_attr($settings['secretary_phone']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row">Ofis Sekreteri E-posta Adresi</th>
                            <td><input type="email" name="secretary_email" value="<?php echo esc_attr($settings['secretary_email']); ?>" class="regular-text" /><p class="description">Mutabakat sağlandığında sekretere otomatik bildirim maili gönderilir.</p></td>
                        </tr>
                        <tr>
                            <th scope="row">Ofis / Kurum Adı</th>
                            <td><input type="text" name="office_name" value="<?php echo esc_attr($settings['office_name']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row">WhatsApp Grup Davet Bağlantısı</th>
                            <td><input type="url" name="whatsapp_group_link" value="<?php echo esc_attr($settings['whatsapp_group_link']); ?>" class="large-text" /><p class="description">Danışanın irade onayı sonrası otomatik tıklayıp katılacağı WhatsApp grubu davet linki.</p></td>
                        </tr>
                        <tr>
                            <th scope="row">WhatsApp Grup Adı</th>
                            <td><input type="text" name="whatsapp_group_name" value="<?php echo esc_attr($settings['whatsapp_group_name']); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row">İrade Beyanı Onay Metni</th>
                            <td><textarea name="voluntary_text" rows="3" class="large-text"><?php echo esc_textarea($settings['voluntary_text']); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row">Seans Süresi (Dakika)</th>
                            <td><input type="number" name="slot_duration" value="<?php echo esc_attr($settings['slot_duration']); ?>" class="small-text" /></td>
                        </tr>
                        <tr>
                            <th scope="row">Mesai Başlangıç - Bitiş</th>
                            <td>
                                <input type="time" name="work_start" value="<?php echo esc_attr($settings['work_start']); ?>" /> -
                                <input type="time" name="work_end" value="<?php echo esc_attr($settings['work_end']); ?>" />
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Ayarları Kaydet', 'primary', 'orp_save_settings'); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}

new Otomatik_Randevu_Programi();
