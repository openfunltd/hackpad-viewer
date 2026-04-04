<?php
/**
 * Pure PHP reader for MaxMind GeoIP Legacy Country database (.dat format).
 * No extension or Composer required.
 */
class GeoIP
{
    const COUNTRY_BEGIN  = 16776960;
    const RECORD_LENGTH  = 3;
    const DB_PATH        = '/usr/share/GeoIP/GeoIP.dat';

    private static $fh = null;

    // ISO 3166 country codes indexed by MaxMind GeoIP country offset
    private static $countries = [
        '','AP','EU','AD','AE','AF','AG','AI','AL','AM','CW','AO','AQ','AR','AS','AT',
        'AU','AW','AZ','BA','BB','BD','BE','BF','BG','BH','BI','BJ','BM','BN','BO',
        'BR','BS','BT','BV','BW','BY','BZ','CA','CC','CD','CF','CG','CH','CI','CK',
        'CL','CM','CN','CO','CR','CU','CV','CX','CY','CZ','DE','DJ','DK','DM','DO',
        'DZ','EC','EE','EG','EH','ER','ES','ET','FI','FJ','FK','FM','FO','FR','SX',
        'GA','GB','GD','GE','GF','GH','GI','GL','GM','GN','GP','GQ','GR','GS','GT',
        'GU','GW','GY','HK','HM','HN','HR','HT','HU','ID','IE','IL','IN','IO','IQ',
        'IR','IS','IT','JM','JO','JP','KE','KG','KH','KI','KM','KN','KP','KR','KW',
        'KY','KZ','LA','LB','LC','LI','LK','LR','LS','LT','LU','LV','LY','MA','MC',
        'MD','MG','MH','MK','ML','MM','MN','MO','MP','MQ','MR','MS','MT','MU','MV',
        'MW','MX','MY','MZ','NA','NC','NE','NF','NG','NI','NL','NO','NP','NR','NU',
        'NZ','OM','PA','PE','PF','PG','PH','PK','PL','PM','PN','PR','PS','PT','PW',
        'PY','QA','RE','RO','RU','RW','SA','SB','SC','SD','SE','SG','SH','SI','SJ',
        'SK','SL','SM','SN','SO','SR','ST','SV','SY','SZ','TC','TD','TF','TG','TH',
        'TJ','TK','TM','TN','TO','TL','TR','TT','TV','TW','TZ','UA','UG','UM','US',
        'UY','UZ','VA','VC','VE','VG','VI','VN','VU','WF','WS','YE','YT','RS','ZA',
        'ZM','ME','ZW','A1','A2','O1','AX','GG','IM','JE','BL','MF','BQ','SS','O1',
    ];

    /**
     * Look up country code for an IPv4 address (or IPv4-mapped IPv6).
     * Returns ISO 3166-1 alpha-2 code (e.g. "TW"), or '' if not found.
     */
    public static function countryCode(string $ip): string
    {
        // Strip IPv4-mapped IPv6 prefix (::ffff:x.x.x.x)
        $ip = preg_replace('/^::ffff:/i', '', $ip);

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return '';
        }

        if (self::$fh === null) {
            self::$fh = @fopen(self::DB_PATH, 'rb');
            if (!self::$fh) return '';
        }

        $ipLong = ip2long($ip);
        $node   = 0;

        for ($i = 31; $i >= 0; $i--) {
            $bit = ($ipLong >> $i) & 1;
            fseek(self::$fh, $node * 6 + $bit * 3);
            $buf  = fread(self::$fh, 3);
            $x    = unpack('C3', $buf);
            $node = $x[1] + ($x[2] << 8) + ($x[3] << 16);

            if ($node >= self::COUNTRY_BEGIN) {
                return self::$countries[$node - self::COUNTRY_BEGIN] ?? '';
            }
        }

        return '';
    }
}
