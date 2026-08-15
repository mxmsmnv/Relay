<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * Read-only iCalendar publication feed and its administration screen.
 */
trait RelayCalendarFeedTrait
{
    private const CALENDAR_FEED_SESSION_KEY = 'RelayCalendarFeedTokenOnce';

    public function handleCalendarFeed(HookEvent $event): string
    {
        $token = preg_replace('/\.ics$/i', '', trim((string) $event->arguments('token'))) ?? '';
        if (!$this->calendarFeedTokenIsValid($token)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('X-Robots-Tag: noindex, nofollow, noarchive');
            return 'Not found';
        }

        $calendar = $this->buildCalendarFeed();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="relay-calendar.ics"');
        header('Content-Length: ' . strlen($calendar));
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Referrer-Policy: no-referrer');
        return $calendar;
    }

    public function ___executeCalendarFeed(): string
    {
        $this->requireInterfaceAdmin();
        if ($this->wire('input')->requestMethod('POST')) $this->handleCalendarFeedAction();

        $this->configureAdminChrome($this->_('Relay Calendar subscriptions'), $this->_('Calendar subscriptions'), [
            [$this->processUrl() . 'interfaces/', $this->_('Interfaces')],
        ]);
        $configured = trim((string) $this->calendar_feed_token_hash) !== '';
        $enabled = (int) $this->calendar_feed_enabled === 1 && $configured;
        $once = trim((string) $this->wire('session')->get(self::CALENDAR_FEED_SESSION_KEY));
        $this->wire('session')->remove(self::CALENDAR_FEED_SESSION_KEY);
        $https = $once !== '' ? $this->calendarFeedUrl($once) : '';
        $webcal = $https !== '' ? preg_replace('/^https?:/i', 'webcal:', $https) : '';
        $secureTransport = str_starts_with(strtolower($this->calendarFeedBaseUrl()), 'https://');

        $out = $this->interfaceNav('calendar-feed') . $this->interfaceIntro(
            $this->_('Read-only calendar subscriptions'),
            $this->_('Publish Relay dates to Google Calendar, Apple Calendar, and other iCalendar clients without granting control of jobs or pages.'),
            [
                'Feed' => [$enabled, $this->_('Active'), $this->_('Inactive')],
                'Token' => [$configured, $this->_('Configured'), $this->_('Not configured')],
                'HTTPS' => [$secureTransport, $this->_('Secure'), $this->_('Required')],
            ]
        );

        if ($once !== '') {
            $out .= $this->renderCalendarFeedTokenOnce($https, (string) $webcal);
        }

        $out .= $this->interfaceSettings($this->_('Subscription feed'), [
            $this->_('Feed') => $enabled ? $this->_('Enabled') : $this->_('Disabled'),
            $this->_('Credential') => $configured ? $this->_('SHA-256 hash stored') : $this->_('Not configured'),
            $this->_('Created / rotated') => (string) $this->calendar_feed_token_created_at ?: $this->_('Never'),
            $this->_('Past range') => sprintf($this->_n('%d day', '%d days', (int) $this->calendar_feed_past_days), (int) $this->calendar_feed_past_days),
            $this->_('Future range') => sprintf($this->_n('%d day', '%d days', (int) $this->calendar_feed_future_days), (int) $this->calendar_feed_future_days),
            $this->_('Page titles') => (int) $this->calendar_feed_include_titles === 1 ? $this->_('Included') : $this->_('Hidden'),
            $this->_('Public page links') => (int) $this->calendar_feed_include_links === 1 ? $this->_('Included') : $this->_('Hidden'),
        ], $this->processUrl() . 'calendar-feed/#RelayCalendarFeedControls');
        $out .= $this->renderCalendarFeedControls($configured, $enabled, $secureTransport);
        $out .= $this->interfaceSafety(
            $this->_('Secret-link boundary'),
            $this->_('Anyone with the subscription URL can read its events'),
            $this->_('Relay stores only a SHA-256 hash of the 256-bit token. Keep the URL out of tickets, analytics, public documents, and screenshots; rotate it immediately if exposed. Internal notes, users, errors, page content, and non-public links are never exported.')
        );
        return $this->interfaceWrap($out);
    }

    private function calendarFeedTokenIsValid(string $token): bool
    {
        if ((int) $this->calendar_feed_enabled !== 1 || !preg_match('/^relay_calendar_[A-Za-z0-9_-]{43}$/D', $token)) return false;
        $stored = trim((string) $this->calendar_feed_token_hash);
        return strlen($stored) === 64 && hash_equals($stored, hash('sha256', $token));
    }

    private function newCalendarFeedToken(): string
    {
        return 'relay_calendar_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function buildCalendarFeed(): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $pastDays = max(0, min(3650, (int) $this->calendar_feed_past_days));
        $futureDays = max(1, min(3650, (int) $this->calendar_feed_future_days));
        $jobs = $this->isImitationMode() ? [] : $this->store()->between(
            $now->modify('-' . $pastDays . ' days'),
            $now->modify('+' . $futureDays . ' days'),
            5000
        );

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Relay//Read-only publication calendar//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->icsEscape((string) $this->_('Relay publication schedule')),
            'X-WR-CALDESC:' . $this->icsEscape((string) $this->_('Read-only scheduled publication actions from Relay.')),
            'REFRESH-INTERVAL;VALUE=DURATION:PT15M',
            'X-PUBLISHED-TTL:PT15M',
        ];
        $host = (string) (parse_url($this->calendarFeedBaseUrl(), PHP_URL_HOST) ?: 'relay.local');
        foreach ($jobs as $job) {
            if (!in_array((string) ($job['status'] ?? ''), ['scheduled', 'processing', 'completed'], true)) continue;
            $page = $this->wire('pages')->get((int) ($job['page_id'] ?? 0));
            if (!$page || !$page->id) continue;
            $action = (string) ($job['action'] ?? '') === 'unpublish' ? 'Unpublish' : 'Publish';
            $summary = $action . ' · Scheduled publication';
            if ((int) $this->calendar_feed_include_titles === 1 && trim((string) $page->title) !== '') {
                $summary = $action . ' · ' . trim((string) $page->title);
            }
            $scheduled = new \DateTimeImmutable((string) $job['scheduled_at'], new \DateTimeZone('UTC'));
            $updated = new \DateTimeImmutable((string) ($job['updated_at'] ?: $job['created_at']), new \DateTimeZone('UTC'));
            $description = sprintf('Relay %s action · %s', strtolower($action), (string) $job['status']);
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:relay-job-' . (int) $job['id'] . '@' . $host;
            $lines[] = 'DTSTAMP:' . $updated->format('Ymd\THis\Z');
            $lines[] = 'LAST-MODIFIED:' . $updated->format('Ymd\THis\Z');
            $lines[] = 'DTSTART:' . $scheduled->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:' . $this->icsEscape($summary);
            $lines[] = 'DESCRIPTION:' . $this->icsEscape($description);
            $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'CLASS:PRIVATE';
            $lines[] = 'TRANSP:TRANSPARENT';
            if ((int) $this->calendar_feed_include_links === 1 && $page->viewable()) {
                $url = trim((string) $page->httpUrl);
                if (preg_match('#^https?://#i', $url)) $lines[] = 'URL:' . $this->icsEscape($url);
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(fn(string $line): string => $this->icsFoldLine($line), $lines)) . "\r\n";
    }

    private function icsEscape(string $value): string
    {
        return str_replace(["\\", "\r\n", "\r", "\n", ';', ','], ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'], $value);
    }

    private function icsFoldLine(string $line): string
    {
        if (strlen($line) <= 75) return $line;
        $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $result = [];
        $chunk = '';
        $limit = 75;
        foreach ($characters as $character) {
            if ($chunk !== '' && strlen($chunk . $character) > $limit) {
                $result[] = $chunk;
                $chunk = $character;
                $limit = 74;
            } else {
                $chunk .= $character;
            }
        }
        if ($chunk !== '') $result[] = $chunk;
        return implode("\r\n ", $result);
    }

    private function calendarFeedBaseUrl(): string
    {
        $root = $this->wire('pages')->get(1);
        $url = $root && $root->id ? (string) $root->httpUrl : '';
        if ($url === '') $url = (string) ($this->wire('config')->urls->httpRoot ?? '');
        return rtrim($url, '/') . '/';
    }

    private function calendarFeedUrl(string $token): string
    {
        return $this->calendarFeedBaseUrl() . 'relay-calendar/' . rawurlencode($token) . '.ics';
    }

    private function renderCalendarFeedTokenOnce(string $https, string $webcal): string
    {
        $sanitizer = $this->wire('sanitizer');
        return '<section class="RelayCalendarFeedOnce" role="status" data-copy-success="' . $sanitizer->entities($this->_('Copied')) . '" data-copy-failed="' . $sanitizer->entities($this->_('Copy failed')) . '"><header><div><small>' . $this->_('Copy now') . '</small><h2>' . $this->_('New calendar subscription URL') . '</h2><p>' . $this->_('Shown once. Add the HTTPS URL to Google Calendar or open the Apple subscription link before leaving this page.') . '</p></div><i class="fa fa-calendar-check-o" aria-hidden="true"></i></header><div class="RelayCalendarFeedOnce__row"><code data-relay-calendar-url>' . $sanitizer->entities($https) . '</code><button type="button" class="uk-button uk-button-default" data-relay-copy-calendar><i class="fa fa-copy" aria-hidden="true"></i><span>' . $this->_('Copy URL') . '</span></button></div><div class="RelayCalendarFeedOnce__actions"><a class="uk-button uk-button-default" href="https://calendar.google.com/calendar/u/0/r/settings/addbyurl" target="_blank" rel="noopener noreferrer"><i class="fa fa-google" aria-hidden="true"></i><span>' . $this->_('Open Google Calendar') . '</span></a><a class="uk-button uk-button-default" href="' . $sanitizer->entities($webcal) . '"><i class="fa fa-apple" aria-hidden="true"></i><span>' . $this->_('Subscribe in Apple Calendar') . '</span></a></div></section>';
    }

    private function renderCalendarFeedControls(bool $configured, bool $enabled, bool $secureTransport): string
    {
        $sanitizer = $this->wire('sanitizer');
        $token = $this->wire('session')->CSRF->getToken();
        $csrf = '<input type="hidden" name="' . $sanitizer->entities((string) $token['name']) . '" value="' . $sanitizer->entities((string) $token['value']) . '">';
        $out = '<section class="RelayCalendarFeedPanel" id="RelayCalendarFeedControls" tabindex="-1"><header><div class="RelayCalendarFeedHeading"><span><i class="fa fa-calendar" aria-hidden="true"></i></span><div><small>iCalendar</small><h2>' . $this->_('Subscription access') . '</h2><p>' . $this->_('Use one revocable secret URL for read-only calendar clients. Relay never receives Google or Apple credentials.') . '</p></div></div>' . $this->interfaceState($this->_('Feed'), $enabled, $this->_('Active'), $this->_('Inactive')) . '</header>';
        if (!$secureTransport) $out .= '<p class="RelayWorkerNotice"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> ' . $this->_('HTTPS is required before sharing this subscription URL.') . '</p>';
        if ($configured) {
            $out .= '<form method="post" class="RelayCalendarFeedForm"><input type="hidden" name="calendar_feed_action" value="save">' . $csrf
                . '<div class="RelayCalendarFeedForm__column"><label class="RelayWorkerToggle RelayCalendarFeedForm__primary"><input type="checkbox" name="calendar_feed_enabled" value="1"' . ($enabled ? ' checked' : '') . '><span><strong>' . $this->_('Enable calendar feed') . '</strong><small>' . $this->_('Pause or resume access without changing the secret URL.') . '</small></span></label>'
                . '<div class="RelayCalendarFeedRange"><label>' . $this->_('Past days') . '<input class="uk-input" type="number" name="calendar_feed_past_days" min="0" max="3650" value="' . (int) $this->calendar_feed_past_days . '"></label>'
                . '<label>' . $this->_('Future days') . '<input class="uk-input" type="number" name="calendar_feed_future_days" min="1" max="3650" value="' . (int) $this->calendar_feed_future_days . '"></label></div></div>'
                . '<div class="RelayCalendarFeedForm__column RelayCalendarFeedPrivacy"><label class="RelayWorkerToggle"><input type="checkbox" name="calendar_feed_include_titles" value="1"' . ((int) $this->calendar_feed_include_titles === 1 ? ' checked' : '') . '><span><strong>' . $this->_('Include page titles') . '</strong><small>' . $this->_('May reveal titles of unpublished pages to anyone holding the URL.') . '</small></span></label>'
                . '<label class="RelayWorkerToggle"><input type="checkbox" name="calendar_feed_include_links" value="1"' . ((int) $this->calendar_feed_include_links === 1 ? ' checked' : '') . '><span><strong>' . $this->_('Include public page links') . '</strong><small>' . $this->_('Only links viewable to the public visitor are exported.') . '</small></span></label></div>'
                . '<div class="RelayCalendarFeedForm__actions"><button class="uk-button uk-button-primary" type="submit"><i class="fa fa-check" aria-hidden="true"></i><span>' . $this->_('Save feed settings') . '</span></button></div></form>';
        }
        $out .= '<div class="RelayCalendarFeedCredentials"><form method="post"><input type="hidden" name="calendar_feed_action" value="rotate">' . $csrf . '<button class="uk-button uk-button-default" type="submit"><i class="fa fa-refresh" aria-hidden="true"></i><span>' . ($configured ? $this->_('Rotate secret URL') : $this->_('Generate secret URL')) . '</span></button></form>';
        if ($configured) $out .= '<form method="post"><input type="hidden" name="calendar_feed_action" value="revoke">' . $csrf . '<button class="uk-button uk-button-danger" type="submit"><i class="fa fa-ban" aria-hidden="true"></i><span>' . $this->_('Revoke access') . '</span></button></form>';
        return $out . '</div></section>';
    }

    private function handleCalendarFeedAction(): void
    {
        $this->wire('session')->CSRF->validate();
        $action = (string) $this->wire('input')->post('calendar_feed_action');
        $config = array_merge(self::getDefaultConfig(), (array) $this->wire('modules')->getConfig('Relay'));
        if ($action === 'rotate') {
            $token = $this->newCalendarFeedToken();
            $config['calendar_feed_token_hash'] = hash('sha256', $token);
            $config['calendar_feed_token_created_at'] = date('Y-m-d H:i:s');
            $config['calendar_feed_enabled'] = 1;
            $this->wire('session')->set(self::CALENDAR_FEED_SESSION_KEY, $token);
        } elseif ($action === 'revoke') {
            $config['calendar_feed_enabled'] = 0;
            $config['calendar_feed_token_hash'] = '';
            $config['calendar_feed_token_created_at'] = '';
            $this->wire('session')->remove(self::CALENDAR_FEED_SESSION_KEY);
        } elseif ($action === 'save') {
            if (trim((string) ($config['calendar_feed_token_hash'] ?? '')) === '') throw new WireException($this->_('Generate a secret URL before enabling the calendar feed.'));
            $config['calendar_feed_enabled'] = $this->wire('input')->post('calendar_feed_enabled') !== null ? 1 : 0;
            $config['calendar_feed_include_titles'] = $this->wire('input')->post('calendar_feed_include_titles') !== null ? 1 : 0;
            $config['calendar_feed_include_links'] = $this->wire('input')->post('calendar_feed_include_links') !== null ? 1 : 0;
            $config['calendar_feed_past_days'] = max(0, min(3650, (int) $this->wire('input')->post('calendar_feed_past_days')));
            $config['calendar_feed_future_days'] = max(1, min(3650, (int) $this->wire('input')->post('calendar_feed_future_days')));
        } else {
            throw new WireException($this->_('Unsupported calendar feed action.'));
        }
        $this->wire('modules')->saveConfig('Relay', $config);
        $this->wire('session')->redirect($this->processUrl() . 'calendar-feed/');
    }
}
