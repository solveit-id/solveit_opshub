<?php

namespace App\Application\ClientTemplates;

class TemplateCatalog
{
    public const VARIABLES = ['contact_salutation', 'client_name', 'project_name', 'service_name', 'domain_name', 'provider_name', 'expiry_display', 'action_deadline_display', 'impact_summary', 'requested_action', 'invoice_reference', 'amount_display', 'approval_summary', 'safe_access_instruction', 'sender_name'];

    public function definitions(): array
    {
        $bodies = [
            'TPL-01' => "Halo {{contact_salutation}}, kami dari Solveit Indonesia ingin mengingatkan bahwa layanan {{service_name}} untuk {{project_name}} tercatat akan berakhir pada {{expiry_display}}.\n\nMohon konfirmasi rencana perpanjangannya agar layanan website tetap dapat berjalan. Jika layanan tidak diperpanjang, akses website dapat terdampak sesuai ketentuan provider.\n\nJika membutuhkan bantuan, kami siap mendampingi proses perpanjangan. Terima kasih.",
            'TPL-02' => "Halo {{contact_salutation}}, kami dari Solveit Indonesia ingin mengingatkan bahwa domain {{domain_name}} untuk {{project_name}} tercatat akan berakhir pada {{expiry_display}}.\n\nMohon konfirmasi rencana perpanjangannya. Domain yang tidak diperpanjang dapat menyebabkan website atau layanan email pada domain tersebut tidak dapat diakses, sesuai ketentuan registrar.\n\nKami siap membantu jika ada pertanyaan mengenai prosesnya. Terima kasih.",
            'TPL-03' => "Halo {{contact_salutation}}, izin menindaklanjuti pesan kami sebelumnya mengenai {{service_name}} untuk {{project_name}}, yang tercatat akan berakhir pada {{expiry_display}}.\n\nApakah sudah ada keputusan atau kendala dalam proses perpanjangannya? Mohon informasinya agar kami dapat membantu menyiapkan langkah berikutnya.\n\nTerima kasih, Solveit Indonesia.",
            'TPL-04' => "Halo {{contact_salutation}}, kami sedang memperbarui catatan pemeliharaan {{project_name}}.\n\nMohon bantuannya untuk mengonfirmasi tanggal berakhir layanan {{service_name}} melalui informasi pada panel provider atau bukti layanan terbaru. Informasi ini diperlukan agar kami dapat mengingatkan perpanjangan tepat waktu.\n\nTidak perlu mengirimkan password atau kode OTP melalui chat. Terima kasih, Solveit Indonesia.",
            'TPL-05' => "Halo {{contact_salutation}}, berdasarkan catatan layanan yang kami verifikasi, masa aktif {{service_name}} untuk {{project_name}} telah melewati {{expiry_display}}.\n\nMohon konfirmasi apakah perpanjangan sudah dilakukan. Jika sudah, mohon kirimkan informasi masa aktif terbaru agar kami dapat memverifikasinya. Jika belum, kami siap membantu mengecek langkah yang tersedia melalui provider.\n\nTerima kasih, Solveit Indonesia.",
            'TPL-06' => "Halo {{contact_salutation}}, untuk melanjutkan {{requested_action}} pada {{project_name}}, kami memerlukan akses yang sesuai ke layanan terkait.\n\nMohon berikan akses melalui {{safe_access_instruction}}. Jangan mengirimkan password utama atau kode OTP melalui chat.\n\nKami akan menggunakan akses tersebut sesuai kebutuhan pemeliharaan dan mencatat tindakannya. Terima kasih, Solveit Indonesia.",
            'TPL-07' => "Halo {{contact_salutation}}, hasil pemeriksaan kami menunjukkan bahwa {{impact_summary}} pada layanan {{service_name}} untuk {{project_name}}.\n\nKami merekomendasikan {{requested_action}} agar layanan tetap berjalan dengan baik. Mohon konfirmasi apakah langkah tersebut dapat dilanjutkan. Rincian pilihan dan biaya, jika ada, akan kami konfirmasikan terlebih dahulu.\n\nTerima kasih, Solveit Indonesia.",
            'TPL-08' => "Halo {{contact_salutation}}, kami berencana melakukan pemeliharaan pada {{project_name}} dengan cakupan {{approval_summary}}.\n\nMohon konfirmasi persetujuan dan waktu yang sesuai sebelum kami melanjutkan. Jika diperlukan penghentian layanan sementara, perkiraan dampak dan durasinya akan kami jelaskan pada rencana pekerjaan.\n\nTerima kasih, Solveit Indonesia.",
            'TPL-09' => "Halo {{contact_salutation}}, kami mendeteksi kendala akses pada {{project_name}} dan tim Solveit Indonesia sedang melakukan pemeriksaan.\n\nKami akan menyampaikan perkembangan setelah hasil pemeriksaan tersedia. Apabila ada tindakan yang memerlukan bantuan dari pihak {{client_name}}, kami akan menghubungi kembali dengan penjelasan yang jelas.\n\nTerima kasih atas pengertiannya.",
            'TPL-10' => "Halo {{contact_salutation}}, perpanjangan {{service_name}} untuk {{project_name}} telah kami verifikasi. Masa aktif terbaru tercatat sampai {{expiry_display}}.\n\nCatatan pemeliharaan dan jadwal pengingat telah kami perbarui. Terima kasih atas kerja samanya.\n\nSolveit Indonesia.",
        ];
        $extra = ['TPL-01' => ['service_name', 'expiry_display'], 'TPL-02' => ['domain_name', 'expiry_display'], 'TPL-03' => ['service_name', 'expiry_display'], 'TPL-04' => ['service_name'], 'TPL-05' => ['service_name', 'expiry_display'], 'TPL-06' => ['requested_action', 'safe_access_instruction'], 'TPL-07' => ['service_name', 'impact_summary', 'requested_action'], 'TPL-08' => ['approval_summary'], 'TPL-09' => [], 'TPL-10' => ['service_name', 'expiry_display']];
        $triggers = ['TPL-01' => 'verified_hosting_expiry', 'TPL-02' => 'verified_domain_expiry', 'TPL-03' => 'recorded_contact_overdue', 'TPL-04' => 'expiry_unknown', 'TPL-05' => 'verified_expiry_elapsed', 'TPL-06' => 'approved_secure_access', 'TPL-07' => 'reviewed_capacity_recommendation', 'TPL-08' => 'reviewed_maintenance_plan', 'TPL-09' => 'verified_incident', 'TPL-10' => 'verified_renewal'];
        $definitions = [];
        foreach ($bodies as $key => $body) {
            if (in_array($key, ['TPL-01', 'TPL-02', 'TPL-03'], true)) {
                $body .= "\n\n[[if action_deadline_display]]Mohon konfirmasi paling lambat {{action_deadline_display}}.[[endif]]";
            }
            $definitions[$key] = ['body' => $body, 'mandatory_variables' => ['contact_salutation', 'client_name', 'project_name', ...$extra[$key]], 'allowed_variables' => self::VARIABLES, 'trigger' => $triggers[$key]];
        }

        return $definitions;
    }
}
