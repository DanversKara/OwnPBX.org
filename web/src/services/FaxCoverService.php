<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/text_fax_helper.php';

/**
 * The cover page of a sent fax: one template for the whole organisation, set on the Fax Units page.
 * The cover is made as a PDF and goes in front of the document before it becomes a fax image, so the
 * archived PDF shows what was sent.
 */
final class FaxCoverService
{
    public const MODES = ['off', 'optional', 'required'];

    /** @return array{mode: string, company: string, address: string, phone: string, email: string, website: string, notice: string, logo: bool, logo_file: string} */
    public static function settings(): array
    {
        $mode = (string) getSystemSetting('fax_cover_mode', 'off');
        return [
            'mode' => in_array($mode, self::MODES, true) ? $mode : 'off',
            'company' => (string) getSystemSetting('fax_cover_company', ''),
            'address' => (string) getSystemSetting('fax_cover_address', ''),
            'phone' => (string) getSystemSetting('fax_cover_phone', ''),
            'email' => (string) getSystemSetting('fax_cover_email', ''),
            'website' => (string) getSystemSetting('fax_cover_website', ''),
            'notice' => (string) getSystemSetting('fax_cover_notice', ''),
            'logo' => getSystemSetting('fax_cover_logo', '0') === '1',
            'logo_file' => (string) getSystemSetting('fax_cover_logo_file', ''),
        ];
    }

    /** Where the dedicated fax logo lives (web-readable, so the settings page can preview it). */
    public static function logoDir(): string
    {
        return dirname(__DIR__, 2) . '/assets/images/fax-brand';
    }

    /** URL of the dedicated fax logo for the settings page preview; '' when none is uploaded. */
    public static function logoUrl(): string
    {
        $file = (string) getSystemSetting('fax_cover_logo_file', '');
        if ($file === '') {
            return '';
        }
        $path = self::logoDir() . '/' . basename($file);
        return is_file($path) ? '/assets/images/fax-brand/' . basename($file) : '';
    }

    /** @return list<array{id: int|string, name: string, company: string, address: string, phone: string, email: string, website: string, notice: string, logo: int|string, logo_path: string}> a user's own templates */
    public static function templatesFor(int $userId): array
    {
        $stmt = getDB()->prepare('SELECT id, name, company, address, phone, email, website, notice, logo, logo_path FROM pbx_fax_cover_templates WHERE user_id = ? ORDER BY name');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The organisation's settings with the template the sender chose. A template is its owner's: an
     * id of someone else's falls back to the organisation's, except for an administrator.
     *
     * @return array{mode: string, company: string, address: string, phone: string, email: string, website: string, notice: string, logo: bool, logo_file: string}
     */
    public static function settingsFor(array $post, int $userId, string $role): array
    {
        $settings = self::settings();
        $id = (int) ($post['cover_template'] ?? 0);
        if ($id <= 0) {
            return $settings;
        }
        $stmt = getDB()->prepare('SELECT user_id, company, address, phone, email, website, notice, logo, logo_path FROM pbx_fax_cover_templates WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || ((int) $row['user_id'] !== $userId && $role !== 'admin')) {
            return $settings;
        }
        return [
            'mode' => $settings['mode'],
            'company' => (string) $row['company'],
            'address' => (string) ($row['address'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'website' => (string) ($row['website'] ?? ''),
            'notice' => (string) $row['notice'],
            'logo' => (int) $row['logo'] === 1,
            // A template's own logo file wins; otherwise the organisation's fax logo.
            'logo_file' => (string) ($row['logo_path'] ?? '') !== '' ? (string) $row['logo_path'] : $settings['logo_file'],
        ];
    }

    public static function saveTemplate(array $post, int $userId): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $name = mb_substr(trim((string) ($post['template_name'] ?? '')), 0, 60);
        if ($name === '') {
            return ['success' => false, 'error' => t('fax_cover.err_template_name')];
        }
        $id = (int) ($post['template_id'] ?? 0);
        $db = getDB();
        $same = $db->prepare('SELECT id FROM pbx_fax_cover_templates WHERE user_id = ? AND name = ? AND id <> ?');
        $same->execute([$userId, $name, $id]);
        if ($same->fetchColumn() !== false) {
            return ['success' => false, 'error' => t('fax_cover.err_template_taken')];
        }
        $values = [
            $name,
            mb_substr(trim((string) ($post['template_company'] ?? '')), 0, 120),
            mb_substr(trim((string) ($post['template_address'] ?? '')), 0, 200),
            mb_substr(trim((string) ($post['template_phone'] ?? '')), 0, 30),
            mb_substr(trim((string) ($post['template_email'] ?? '')), 0, 80),
            mb_substr(trim((string) ($post['template_website'] ?? '')), 0, 80),
            mb_substr(trim((string) ($post['template_notice'] ?? '')), 0, 1000),
            empty($post['template_logo']) ? 0 : 1,
        ];
        if ($id > 0) {
            $db->prepare('UPDATE pbx_fax_cover_templates SET name = ?, company = ?, address = ?, phone = ?, email = ?, website = ?, notice = ?, logo = ? WHERE id = ? AND user_id = ?')->execute([...$values, $id, $userId]);
        } else {
            $db->prepare('INSERT INTO pbx_fax_cover_templates (name, company, address, phone, email, website, notice, logo, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([...$values, $userId]);
        }
        return ['success' => true, 'message' => sprintf(t('fax_cover.template_saved'), $name)];
    }

    public static function deleteTemplate(array $post, int $userId): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        getDB()->prepare('DELETE FROM pbx_fax_cover_templates WHERE id = ? AND user_id = ?')->execute([(int) ($post['template_id'] ?? 0), $userId]);
        return ['success' => true, 'message' => t('fax_cover.template_deleted')];
    }

    public static function saveSettings(array $post, array $files = []): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $values = [
            'fax_cover_mode' => in_array($post['fax_cover_mode'] ?? '', self::MODES, true) ? $post['fax_cover_mode'] : 'off',
            'fax_cover_company' => mb_substr(trim((string) ($post['fax_cover_company'] ?? '')), 0, 120),
            'fax_cover_address' => mb_substr(trim((string) ($post['fax_cover_address'] ?? '')), 0, 200),
            'fax_cover_phone' => mb_substr(trim((string) ($post['fax_cover_phone'] ?? '')), 0, 30),
            'fax_cover_email' => mb_substr(trim((string) ($post['fax_cover_email'] ?? '')), 0, 80),
            'fax_cover_website' => mb_substr(trim((string) ($post['fax_cover_website'] ?? '')), 0, 80),
            'fax_cover_notice' => mb_substr(trim((string) ($post['fax_cover_notice'] ?? '')), 0, 1000),
            'fax_cover_logo' => empty($post['fax_cover_logo']) ? '0' : '1',
        ];
        $stmt = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($values as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        // Logo upload / removal ride along with the same save.
        $files = $files ?: $_FILES;
        if (!empty($post['remove_fax_cover_logo'])) {
            self::removeLogo();
        } elseif (isset($files['fax_cover_logo_file']) && ($files['fax_cover_logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $res = self::uploadLogo($files['fax_cover_logo_file']);
            if (!$res['success']) {
                return $res;
            }
        }
        writeAuditLog(null, 'fax_cover', 'general', 'Fax cover page settings', 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t('fax_cover.saved')];
    }

    /** Store an uploaded fax logo (PNG/JPG, ≤ 5 MB) and point the setting at it. */
    public static function uploadLogo(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => t('fax_cover.err_logo_upload')];
        }
        if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
            return ['success' => false, 'error' => t('fax_cover.err_logo_size')];
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            return ['success' => false, 'error' => t('fax_cover.err_logo_type')];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['success' => false, 'error' => t('fax_cover.err_logo_upload')];
        }
        $dir = self::logoDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return ['success' => false, 'error' => t('fax_cover.err_logo_upload')];
        }
        $name = 'fax-logo-' . time() . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            return ['success' => false, 'error' => t('fax_cover.err_logo_upload')];
        }
        @chmod($dir . '/' . $name, 0644);
        $old = (string) getSystemSetting('fax_cover_logo_file', '');
        $stmt = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute(['fax_cover_logo_file', $name]);
        if ($old !== '' && $old !== $name) {
            @unlink($dir . '/' . basename($old));
        }
        return ['success' => true, 'message' => t('fax_cover.logo_uploaded')];
    }

    /** Remove the uploaded fax logo (the cover then falls back to the Appearance logo). */
    public static function removeLogo(): void
    {
        $old = (string) getSystemSetting('fax_cover_logo_file', '');
        $stmt = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute(['fax_cover_logo_file', '']);
        if ($old !== '') {
            @unlink(self::logoDir() . '/' . basename($old));
        }
    }

    /** Should this fax get a cover? An administrator's "required" cannot be turned off in the form. */
    public static function wanted(array $post, ?array $settings = null): bool
    {
        $mode = ($settings ?? self::settings())['mode'];
        return $mode === 'required' || ($mode === 'optional' && !empty($post['cover_enabled']));
    }

    /**
     * What the sender typed, limited in length.
     *
     * @return array{to: string, subject: string, message: string}
     */
    public static function fieldsFromPost(array $post): array
    {
        return [
            'to' => mb_substr(trim((string) ($post['cover_to'] ?? '')), 0, 120),
            'subject' => mb_substr(trim((string) ($post['cover_subject'] ?? '')), 0, 200),
            'message' => mb_substr(trim((string) ($post['cover_message'] ?? '')), 0, 2000),
        ];
    }

    /**
     * The page as HTML for mPDF. Everything from people is escaped, so no tag or address in it can
     * reach mPDF. Black on white only: a fax has no greys.
     *
     * Layout matches the old OwnPBX cover: logo and company details top-left,
     * a large FAX top-right, a rule, then the detail rows (To, Fax, From,
     * Company, Phone, Date, Pages, Subject), the message, and the footer
     * notice at the bottom.
     *
     * @param array{to: string, subject: string, message: string} $fields
     * @param array{company: string, address: string, phone: string, email: string, website: string, notice: string} $settings
     * @param array{name: string, number: string, to_number: string, date: string, pages: int} $facts
     */
    public static function html(array $fields, array $settings, array $facts, ?string $logoPath = null): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $row = static fn(string $label, string $value): string => '<tr><td style="width: 120px; padding: 5px 10px 5px 0; font-weight: bold;">'
            . $e($label) . ':</td><td style="padding: 5px 0;">' . nl2br($e($value)) . '</td></tr>';

        $html = '';
        // Header: logo + company details on the left, a large FAX on the right.
        $html .= '<table style="width: 100%; border-collapse: collapse;"><tr><td style="vertical-align: top;">';
        if ($logoPath !== null) {
            $html .= '<div style="margin-bottom: 2mm;"><img src="' . $e($logoPath) . '" style="max-height: 22mm; max-width: 70mm;"></div>';
        }
        if ($settings['company'] !== '') {
            $size = $logoPath !== null ? '13pt' : '24pt';
            $html .= '<div style="font-size: ' . $size . '; font-weight: bold;">' . $e($settings['company']) . '</div>';
        }
        $info = [];
        if ($settings['address'] !== '') {
            foreach (explode("\n", (string) $settings['address']) as $ln) {
                $ln = trim($ln);
                if ($ln !== '') {
                    $info[] = $ln;
                }
            }
            $info = array_slice($info, 0, 4);
        }
        $contact = [];
        if ($settings['phone'] !== '') {
            $contact[] = 'Tel ' . $settings['phone'];
        }
        if ($settings['email'] !== '') {
            $contact[] = $settings['email'];
        }
        if ($settings['website'] !== '') {
            $contact[] = $settings['website'];
        }
        if (!empty($contact)) {
            $info[] = implode('   ', $contact);
        }
        foreach (array_slice($info, 0, 6) as $ln) {
            $html .= '<div style="font-size: 9.5pt;">' . $e($ln) . '</div>';
        }
        $html .= '</td><td style="vertical-align: top; text-align: right; width: 30%;">';
        $html .= '<div style="font-size: 40pt; font-weight: bold;">FAX</div>';
        $html .= '</td></tr></table>';
        $html .= '<hr style="border: none; border-top: 2px solid #000; margin: 4mm 0 6mm;">';

        $html .= '<table style="width: 100%; font-size: 13pt; border-collapse: collapse;">';
        if ($fields['to'] !== '' || $facts['to_number'] !== '') {
            $html .= $row(t('fax_cover.label_to'), trim($fields['to']));
        }
        if ($facts['to_number'] !== '') {
            $html .= $row(t('fax_cover.label_fax'), $facts['to_number']);
        }
        if ($facts['name'] !== '') {
            $html .= $row(t('fax_cover.label_from'), $facts['name']);
        }
        if ($settings['company'] !== '' && $settings['company'] !== $facts['name']) {
            $html .= $row(t('fax_cover.label_company'), $settings['company']);
        }
        if ($facts['number'] !== '') {
            $html .= $row(t('fax_cover.label_phone'), $facts['number']);
        }
        $html .= $row(t('fax_cover.label_date'), $facts['date']);
        $html .= $row(t('fax_cover.label_pages'), $facts['pages'] . ' ' . t('fax_cover.pages_including_cover'));
        if ($fields['subject'] !== '') {
            $html .= $row(t('fax_cover.label_subject'), $fields['subject']);
        }
        $html .= '</table>';

        if ($fields['message'] !== '') {
            $html .= '<hr style="border: none; border-top: 1px solid #000; margin: 8mm 0 4mm;">';
            $html .= '<div style="font-size: 12pt;">' . nl2br($e($fields['message'])) . '</div>';
        }
        if ($settings['notice'] !== '') {
            $html .= '<div style="font-size: 8.5pt; font-style: italic; border-top: 1px solid #000; padding-top: 2mm; margin-top: 14mm;">' . nl2br($e($settings['notice'])) . '</div>';
        }
        return $html;
    }

    /**
     * The logo for the cover as a file on this server. The dedicated fax logo
     * (uploaded on the Fax Units page) wins; without one, the Appearance logo
     * is used; null when there is neither.
     */
    public static function logoPath(?string $logoFile = null): ?string
    {
        $file = $logoFile ?? (string) getSystemSetting('fax_cover_logo_file', '');
        if ($file !== '') {
            $path = self::logoDir() . '/' . basename($file);
            if (is_file($path) && preg_match('/\.(png|jpe?g)$/i', $path)) {
                return $path;
            }
        }
        $url = (string) preg_replace('/\?.*$/', '', (string) getSystemSetting('site_logo_image', ''));
        if ($url === '') {
            return null;
        }
        require_once __DIR__ . '/BrandSettingsService.php';
        $path = BrandSettingsService::uploadDir() . '/' . basename($url);
        return is_file($path) && preg_match('/\.(png|jpe?g)$/i', $path) ? $path : null;
    }

    /** @return array{name: string, number: string, to_number: string, date: string, pages: int} */
    private static function facts(string $senderExt, string $toNumber, int $pages): array
    {
        $row = DBHelper::fetchOne('SELECT full_name, cid_external FROM sys_users WHERE extension = ? LIMIT 1', [$senderExt]) ?: [];
        return [
            'name' => (string) ($row['full_name'] ?? ''),
            'number' => (string) (($row['cid_external'] ?? '') ?: $senderExt),
            'to_number' => $toNumber,
            'date' => date('F d, Y  h:i A'),
            'pages' => $pages,
        ];
    }

    /** Pages of a PDF, counted the way the fax itself will be: by making the image. */
    public static function pageCount(string $pdf): int
    {
        $tif = tempnam(sys_get_temp_dir(), 'aipbx_cover');
        exec(escapeshellarg(GS_BINARY) . ' -q -dSAFER -dNOPAUSE -dBATCH -sDEVICE=tiffg4 -r50 -sOutputFile=' . escapeshellarg($tif) . ' ' . escapeshellarg($pdf) . ' 2>/dev/null');
        $count = (int) trim((string) shell_exec('tiffinfo ' . escapeshellarg($tif) . " 2>/dev/null | grep -c 'TIFF Directory'"));
        @unlink($tif);
        return $count;
    }

    /**
     * Puts the cover in front of a PDF, replacing the file.
     *
     * @param array{to: string, subject: string, message: string} $fields
     */
    public static function prepend(string $pdf, array $fields, string $senderExt, array $settings, string $toNumber): void
    {
        $pages = self::pageCount($pdf);
        if ($pages < 1) {
            throw new \RuntimeException(t('fax_cover.err_read'));
        }
        $cover = tempnam(sys_get_temp_dir(), 'aipbx_cover');
        $merged = $pdf . '.merged';
        try {
            TextFaxHelper::htmlToPdf(
                self::html($fields, $settings, self::facts($senderExt, $toNumber, $pages + 1), $settings['logo'] ? self::logoPath($settings['logo_file'] ?? null) : null),
                $cover
            );
            exec(escapeshellarg(GS_BINARY) . ' -q -dSAFER -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -sOutputFile=' . escapeshellarg($merged) . ' ' . escapeshellarg($cover) . ' ' . escapeshellarg($pdf) . ' 2>/dev/null', $out, $rc);
            if ($rc !== 0 || !is_file($merged) || filesize($merged) === 0 || !rename($merged, $pdf)) {
                throw new \RuntimeException(t('fax_cover.err_read'));
            }
        } finally {
            @unlink($cover);
            @unlink($merged);
        }
    }

    /**
     * The cover as the far end will receive it: black and white at fax resolution.
     *
     * @param array{company: string, address: string, phone: string, email: string, website: string, notice: string, logo: bool, logo_file?: string} $settings
     */
    public static function previewPng(array $fields, array $settings, string $senderExt, string $toNumber): string
    {
        $cover = tempnam(sys_get_temp_dir(), 'aipbx_cover');
        $png = tempnam(sys_get_temp_dir(), 'aipbx_cover');
        try {
            TextFaxHelper::htmlToPdf(
                self::html($fields, $settings, self::facts($senderExt, $toNumber, 1), $settings['logo'] ? self::logoPath($settings['logo_file'] ?? null) : null),
                $cover
            );
            exec(escapeshellarg(GS_BINARY) . ' -q -dSAFER -dNOPAUSE -dBATCH -sDEVICE=pngmono -r204x196 -dFirstPage=1 -dLastPage=1 -sOutputFile=' . escapeshellarg($png) . ' ' . escapeshellarg($cover) . ' 2>/dev/null');
            return (string) file_get_contents($png);
        } finally {
            @unlink($cover);
            @unlink($png);
        }
    }
}
