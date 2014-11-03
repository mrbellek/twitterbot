<?php
require_once('./twitteroauth.php');
require_once('./dstbot.inc.php');

/*
 * TODO:
 * . get data for all dst settings per country
 * - tweet warning about DST clock change 7 days, 1 day in advance + moment of change
 * - apply for multiple countries, timezones (?)
 * - reply to command: when is next DST in (country)?
 * - reply to command: what time is it now in (country)?
 *   - or get country from profile
 *
 * SPECIAL CASES:
 * - brazil dst end is delayed by 1 week during carnival week, so would be 4th sunday of february instead of 3rd
 */

$o = new DstBot(array('sUsername' => 'DaylightSavings'));
$o->run();

class DstBot {

    private $sUsername;
    private $sLogFile;

    /*
     * GROUPS:
     * - Europe, except Armenia, Belarus, Georgia, Iceland, Russia (and Crimea of Ukrain)
     *   - also includes Lebanon, Morocco, Western Sahara
     * - North America, except Mexico and Greenland
     *   - also includes Cuba, Haiti, Turks and Caicos
     * - Jordan, Palestine, Syria
     * - Samoa, New Zealand
     * - everything else single countries
     * - no DST group
     */

    // all taken from http://en.wikipedia.org/wiki/Daylight_saving_time_by_country
    private $aBetterSettings = array(
        'europe' => array(
            'includes' => array(
                'austria',
                'belgium',
                'etc'
            ),
            'excludes' => array(
                'armenia',
                'belarus',
                'etc'
            ),
            'start' => 'last sunday of march',
            'end' => 'last sunday of october',
        ),
        'north america' => array(
            'includes' => array(
                'bahamas',
                'bermuda',
                'canada',
                'saint pierre and miquelon',
                'united states',
            ),
            'excludes' => array(
                'cuba',
                'haiti',
                'turks and caicos',
            ),
            'start' => 'second sunday of march',
            'end' => 'first sunday of november',
        ),
        //'palestine' => array(
        //'syria' => array(
        'jordan' => array(
            'start' => 'last friday march',
            'end' => 'last friday october',
        ),
        //'new zealand' => array(
        'samoa' => array(
            'start' => 'last sunday september',
            'end' => 'first sunday april',
        ),
        'no dst' => array(
            'includes' => array(
                'afghanistan',
                'algeria',
                'american samoa',
                'etc'
            ),
        ),
    );

    private $aSettings = array(
        'afghanistan' => array(
            'continent' => 'asia',
            'dst' => FALSE,
        ),
        'akrotiri and dhekelia' => array(
            'group' => 'europe',
            'continent' => 'europe',
            'hemisphere' => 'north',
            'dst' => array(
                'start' => 'last sunday of march',
                'end' => 'last sunday of october',
            ),
        ),
        'albania' => array(
            'group' => 'europe',
            'continent' => 'europe',
            'hemisphere' => 'north',
            'dst' => array(
                'start' => 'last sunday of march',
                'end' => 'last sunday of october',
            ),
            'since' => 1974,
        ),
        'algeria' => array(
            'continent' => 'africa',
            'dst' => FALSE,
            'since' => 1981,
        ),
        'american samoa' => array(
            'continent' => 'oceania',
            'dst' => FALSE,
        ),
        'andorra' => array(
            'group' => 'europe',
            'continent' => 'europe',
            'hemisphere' => 'north',
            'dst' => array(
                'start' => 'last sunday of march',
                'end' => 'last sunday of october',
            ),
            'since' => 1985,
        ),
        'angola' => array(
            'continent' => 'africa',
            'dst' => FALSE,
        ),
        'anguilla' => array(
            'continent' => 'central america',
            'dst' => FALSE,
        ),
        'antigua and barbuda' => array(
            'continent' => 'central america',
            'dst' => FALSE,
        ),
        'argentina' => array(
            'continent' => 'south america',
            'dst' => FALSE,
            'since' => 2009,
        ),
        'armenia' => array(
            'group' => 'europe',
            'continent' => 'europe',
            'dst' => FALSE,
            'since' => 2011,
        ),
        'aruba' => array(
            'continent' => 'central america',
            'dst' => FALSE,
        ),
        'australia' => array(
            'aliases' => array(
                'australian capital territory',
                'victoria',
                'new south wales',
                'tasmania',
                'south australia',
                'lord howe island',
            ),
            'continent' => 'oceania',
            'hemisphere' => 'south',
            'dst' => array(
                'start' => 'first sunday of october',
                'end' => 'first sunday of april',
            ),
        ),
        'austria' => array(
            'group' => 'europe',
            'continent' => 'europe',
            'hemisphere' => 'north',
            'dst' => array(
                'start' => 'last sunday of march',
                'end' => 'last sunday of october',
            ),
            'since' => 1980,
        ),
        'azerbaijan' => array(
            'group' => 'europe',
            'continent' => 'europe',
            'hemisphere' => 'north',
            'dst' => array(
                'start' => 'last sunday of march',
                'end' => 'last sunday of october',
            ),
            'since' => 1996,
        ),
        'bahamas' => array(
            'group' => 'north america',
            'continent' => 'north america',
            'hemisphere' => 'north',
            'dst' => array(
                'start' => 'second sunday of march',
                'end' => 'first sunday of november',
            ),
            'since' => 1964,
        ),
        'bahrain' => array(
            'continent' => 'asia',
            'dst' => FALSE,
        ),
        'bangladesh' => array(
            'continent' => 'asia',
            'dst' => FALSE,
            'since' => 2009,
        ),
        'barbados' => array(
            'continent' => 'central america',
            'dst' => FALSE,
            'since' => 1980,
        ),
        'belarus' => array(
            'continent' => 'europe',
            'dst' => FALSE,
            'since' => 2010,
        ),
        'belgium' => array(
            'group' => 'europe',
            'continent' => 'europe',
            'hemisphere' => 'north',
            'dst' => array(
                'start' => 'last sunday of march',
                'end' => 'last sunday of october',
            ),
            'since' => 1977,
        ),
        'belize' => array(
            'continent' => 'central america',
            'dst' => FALSE,
            'since' => 1983,
        ),
        'benin' => array(
            'continent' => 'africa',
            'dst' => FALSE,
        ),
        'bermuda' => array(
            'group' => 'north america',
            'continent' => 'north america',
            'hemisphere' => 'north',
            'dst' => array(
                'start' => 'second sunday of march',
                'end' => 'first sunday of november',
            ),
            'since' => 1974,
        ),
        'bhutan' => array(
            'continent' => 'asia',
            'dst' => FALSE,
        ),
        'bolovia' => array(
            'continent' => 'south america',
            'dst' => FALSE,
            'since' => 1932,
        ),
        'bonaire' => array(
            'continent' => 'central america',
            'dst' => FALSE,
        ),
        'bosnia and herzegovina' => array(
            'group' => 'europe',
            'continent' => 'europe',
            'hemisphere' => 'north',
            'dst' => array(
                'start' => 'last sunday of march',
                'end' => 'last sunday of october',
            ),
            'since' => 1983,
        ),
        'botswana' => array(
            'continent' => 'africa',
            'dst' => FALSE,
            'since' => 1944,
        ),
        'brazil' => array(
            //TODO: DST end is delayed by one week (4th sunday of february) during Carnival Week
            'continent' => 'south america',
            'hemisphere' => 'south',
            'dst' => array(
                'start' => 'third sunday of october',
                'end' => 'third sunday of february',
            ),
        ),
        'british virgin islands' => array(
            'continent' => 'central america',
            'dst' => FALSE,
        ),
        'brunei' => array(
            'continent' => 'asia',
            'dst' => FALSE,
        ),
        'bulgaria' => array(
            'group' => 'europe',
            'continent' => 'europe',
            'hemisphere' => 'north',
            'dst' => array(
                'start' => 'last sunday of march',
                'end' => 'last sunday of october',
            ),
            'since' => 1979,
        ),
        'burkina fasso' => array(
            'continent' => 'africa',
            'dst' => FALSE,
        ),
        'burma' => array(
            'continent' => 'asia',
            'dst' => FALSE,
        ),
        'burundi' => array(
            'continent' => 'africa',
            'dst' => FALSE,
        ),
        'cambodia' => array(
            'continent' => 'asia',
            'dst' => FALSE,
        ),
        'cameroon' => array(
            'continent' => 'africa',
            'dst' => FALSE,
        ),

    /*Canada   North America   Boreal/North   Second Sunday March   First Sunday November
    Main article: Daylight saving time in Canada
    Some regions in Quebec, east of 63° west longitude, most of Saskatchewan, Southampton Island and some areas in British Columbia do not observe DST. Saskatchewan however, observes Central Time even though it is located in the Mountain Time Zone, meaning it effectively observes DST year round.[5]

    Cape Verde   Africa   -   -   -   Observed DST in 1942-1945.
    Cayman Islands (UK)   Central America   -   -   -   Does not use DST
    Central African Republic   Africa   -   -   -   Does not use DST
    Chad   Africa   -   -   -   Observed DST in winter 1979-1980.
    Chile   South America   Austral/South   Second Sunday September   Last Sunday April   Observed DST in 1927-1946 (excluding Easter Island which observed it in 1932-1946 ) and since 1968. Easter Island starts on Saturday to sync with Chile
    China   Asia   -   -   -   Observed DST in 1986-1991.
    Christmas Island (AU)   Asia   -   -   -   Does not use DST
    Cocos Island (AU)   Asia   -   -   -   Does not use DST
    Colombia   South America   -   -   -   Observed DST in 1992-1993.
    Comoros   Africa   -   -   -   Does not use DST
    Cook Islands (NZ)   Oceania   -   -   -   Observed DST in 1978-1991.
    Congo   Africa   -   -   -   Does not use DST
    Costa Rica   Central America   -   -   -   Observed DST in 1954, 1979-1980 and 1991-1992.
    Croatia   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1941-1945 and since 1983.
    Cuba   Central America   Boreal/North   Second Sunday March   First Sunday November   Observed DST in 1928, 1940-1942, 1945-1946 and since 1965.
    Curacao (NL)   Central America   -   -   -   Does not use DST
    Cyprus   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST since 1975.
    Czech Republic   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916-1918, 1940-1949 and since 1979.
    Democratic Republic of Congo   Africa   -   -   -   Does not use DST
    Denmark   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916, 1940-1948 and since 1980.
    Djibouti   Africa   -   -   -   Does not use DST
    Dominica   Central America   -   -   -   Does not use DST
    Dominican Republic   Central America   -   -   -   Observed DST in 1966-1967, 1969-1974.
    East Timor   Asia   -   -   -   Does not use DST
    Ecuador   South America   -   -   -   Does not use DST
    Egypt   Africa/Asia   Boreal/North   Last Friday April   Last Friday September   Observed DST in 1940-1945 and 1957-2010. Re-introduces DST in 2014. DST is stopped during Ramadan
    El Salvador   Central America   -   -   -   Observed DST in 1987-1988.
    Equatorial Guinea   Africa   -   -   -   Does not use DST
    Eritrea   Africa   -   -   -   Does not use DST
    Estonia   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1918, 1940-1944 and since 1981.
    Ethiopia   Africa   -   -   -   Does not use DST
    Faroe Islands (DK)   Europe   Boreal/North   Last Sunday March   Last Sunday October   Observed DST since 1981.
    Falkland Islands (UK)   South America   -   -   -   Keeps on continuous DST since 2011
    Fiji   Oceania   Austral/South   First Sunday November   Third Sunday January   Observed DST in 1998-2000 and since 2009.
    Finland   Europe   Boreal/North   Last Sunday March   Last Sunday October   Observed DST in 1942 and since 1981.
    France   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916-1945 and since 1976.
    French Guyana (FR)   South America   -   -   -   Does not use DST
    French Polynesia (FR)   Oceania   -   -   -   Does not use DST
    French Southern and Antarctic Lands (FR)   Antarctica   -   -   -   Does not use DST
    Gabon   Africa   -   -   -   Does not use DST
    Gambia   Africa   -   -   -   Does not use DST
    Georgia   Europe   -   -   -   Observed DST in 1981-2005.
    Germany   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916-1918, 1940-1949, and since 1980.
    Ghana   Africa   -   -   -   Observed DST from 1936-1942.
    Greece   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916-1968 and since 1971.
    Greenland   North America   Boreal/North   22:00 local time on Saturday before last Sunday March   23:00 local time on Saturday before last Sunday October   Observed DST since 1980.
    Follows European Union practice, although not a member: hence start & end times correspond to 01:00 UTC on the respective Sunday. See Daylight saving time in the Americas—Greenland Qaanaaq uses US and Canada rules. Danmarkshavn does not use DST.

    Grenada   Central America   -   -   -   Does not use DST
    Guadeloupe (FR)   Central America   -   -   -   Does not use DST
    Guam (US)   Oceania   -   -   -   Does not use DST
    Guatemala   Central America   -   -   -   Observed DST in 1973-1974, 1983, 1991 and 2006.
    Guernsey (UK)   Europe   Boreal/North   01:00 GMT on last Sunday March   01:00 GMT on last Sunday October   Observed DST in 1916-1968 and since 1972.
    Guinea   Africa   -   -   -   Does not use DST
    Guinea-Bissau   Africa   -   -   -   Does not use DST
    Guyana   South America   -   -   -   Does not use DST
    Haiti   Central America   Boreal/North   Second Sunday March   First Sunday November   Observed DST in 1983-1997, 2005-2006 and from 2012 onwards.
    Heard and McDonald Islands (AU)   Antarctica   -   -   -   Does not use DST
    Holy See   Europe   Boreal/North   Last Sunday March   Last Sunday October   Observed DST in 1916-1920, 1940-1948 and since 1966.
    Honduras   Central America   -   -   -   Observed DST in 1987-1988 and 2006 .
    Hong Kong   Asia   -   -   -   Observed DST in 1941, 1945-1976 and 1979.
    Hungary   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916-1920, 1941-1950, 1954-1957 and since 1980.
    Iceland   Europe   -   -   -   Observed DST in 1917-1918 and 1939-1968.
    India   Asia   -   -   -   Observed DST in 1942-1945.
    Indonesia   Asia   -   -   -   Does not use DST
    Iraq   Asia   -   -   -   Observed DST in 1982-2007.
    Iran   Asia   Boreal/North   March 21–22   September 21–22   Observed DST in 1977-1980, 1991-2005 and since 2008.
    Ireland   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916-1968 and since 1972.
    Isle of Man (UK)   Europe   Boreal/North   01:00 GMT on last Sunday March   01:00 GMT on last Sunday October   Observed DST in 1916-1968 and since 1972.
    Israel   Asia   Boreal/North   Friday before last Sunday March   Last Sunday October   Observed DST in 1940-1946, 1948-1957, 1974-1975 and since 1985.
    Italy   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916-1920, 1940-1948 and since 1966.
    Ivory Coast   Africa   -   -   -   Does not use DST
    Jamaica   Central America   -   -   -   Observed DST in 1974-1983.
    Japan   Asia   -   -   -   Observed DST in 1948-1951.
    Jersey (UK)   Europe   Boreal/North   01:00 GMT on last Sunday March   01:00 GMT on last Sunday October   Observed DST in 1916-1968 and since 1971.
    Jordan   Asia   Boreal/North   Last Friday March   Last Friday October   Returned again to UTC+2 on Dec, 20 2013, Previsible with DST
    Kazakhstan   Asia   -   -   -   Observed DST in 1981-1990 and 1992-2004.
    Kenya   Africa   -   -   -   Does not use DST
    Kiribati   Oceania      -   -   Does not use DST
    Kosovo   Europe   Boreal/North   Last Sunday March   Last Sunday October   Observed DST since 1983.
    Kuwait   Asia   -   -   -   Does not use DST
    Kyrgyzistan   Asia   -   -   -   Observed DST in 1981-2005.
    Laos   Asia   -   -   -   Does not use DST
    Latvia   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1918-1919, 1941-1944 and since 1981.
    Lebanon   Asia   Boreal/North   Last Sunday March   Last Sunday October   Observed DST in 1920-1923, 1957-1961, 1972-1978 and since 1984.
    Lesotho   Africa   -   -   -   Observed DST in 1943-1944.
    Liberia   Africa   -   -   -   Does not use DST
    Libya   Africa   -   -   -   Observed DST in 1951, 1955, 1957, 1982-1989,1997.and 2013.
    Liechtenstein   Europe   Boreal/North   Last Sunday March   Last Sunday October   Observed DST since 1981.
    Lithuania   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1941-1944, 1981-1999 and since 2003.
    Luxembourg   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916-1946 and since 1977.
    Macao (CH)   Asia   -   -   -   Observed DST in 1961-1980.
    Macedonia   Europe   Boreal/North   Last Sunday March   Last Sunday October   Observed DST in 1941-1945 and since 1983.
    Madagascar   Africa   -   -   -   Observed DST in 1954.
    Malawi   Africa   -   -   -   Does not use DST
    Malaysia   Asia   -   -   -   Observed DST in 1933-1935.
    Maldives   Asia   -   -   -   Does not use DST
    Mali   Africa   -   -   -   Does not use DST
    Malta   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916-1920, 1940-1948 and since 1966.
    Marshall Islands   Oceania   -   -   -   Does not use DST
    Martinica (FR)   Central America   -   -   -   Observed DST in 1980.
    Mauritania   Africa   -   -   -   Does not use DST
    Mauritius   Africa   -   -   -   Observed DST in 1982-1983 and 2008-2009.
    Mexico   North America   Boreal/North   First Sunday April   Last Sunday October   Observe DST since 1996, but Baja California observe DST since 1942. Sonora observed DST 1996–1997. Locations less than 20 km from the US border use US DST.[6]
    Micronesia   Oceania   -   -   -   Does not use DST
    Midway (US)   Oceania   -   -   -   Observed DST in 1956.
    Moldova   Europe   Boreal/North   Last Sunday March   Last Sunday October   Observed DST in 1932-1944, 1981-1989 and since 1991.
    Monaco   Europe   Boreal/North   Last Sunday March   Last Sunday October   Observed DST in 1916-1945 and since 1976.
    Mongolia   Asia   -   -   -   Observed DST in 1983-1998 and 2001-2006.
    Montenegro   Europe   Boreal/North   Last Sunday March   Last Sunday October   Observed DST 1941-1945 and since 1983.
    Montserrat (UK)   Central America   -   -   -   Does not use DST
    Morocco   Africa   Boreal/North   Last Sunday March   Last Sunday October   Observed DST in 1939-1945, 1950, 1967, 1974, 1974-1976 and since 2008. DST stops during Ramadan.
    Mozambique   Africa   -   -   -   Does not use DST
    Namibia   Africa   Austral/South   First Sunday September   First Sunday April   Observed DST in 1942-1943 and since 1994.
    Nauru   Oceania   -   -   -   Does not use DST
    Nepal   Asia   -   -   -   Does not use DST
    Netherlands   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916-1945 and since 1977.
    New Caledonia (FR)   Oceania   -   -   -   Observed DST in 1977-1979 and 1996-1997.
    New Zealand   Oceania   Austral/South   Last Sunday September   First Sunday April   Observed DST in 1927-1946 and since 1974.
    Nicaragua   Central America   -   -   -   Observed DST in 1973-1975, 1979-1980, 1992-1994 and 2005-2006.
    Niger   Africa   -   -   -   Does not use DST
    Nigeria   Africa   -   -   -   Does not use DST
    Niue (NZ)   Oceania   -   -   -   Does not use DST
    North Korea   Asia   -   -   -   Does not use DST
    Northern Mariana Islands (US)   Oceania   -   -   -   Does not use DST
    Norway   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916, 1940-1945, 1959-1965 and since 1980.
    Follows European Union practice, although not a member.

    Oman   Asia   -   -   -   Does not use DST
    Pakistan   Asia   -   -   -   Observed DST in 1942-1945 (as belonging to India) and 2002.
    Palau   Oceania   -   -   -   Does not use DST
    Palestine   Asia   Boreal/North   Last Friday March   Fourth Friday October   Observed DST in 1940-1946, 1957-1967, 1974-1975 and since 1985.
    Panama   Central America   -   -   -   Does not use DST
    Papua New Guinea   Oceania   -   -   -   Does not use DST
    Paraguay   South America   Austral/South   First Sunday October   Fourth Sunday March   Observed DST since 1975. Current start and end dates last updated in 2013.
    Peru   South America   -   -   -   Observed DST in 1938-1940, 1986-1987, 1990 and 1994.
    Philippines   Asia   -   -   -   Observed DST in 1936-1937, 1954, 1978 and 1990.
    Pitcairn Islands (UK)   Oceania   -   -   -   Does not use DST
    Poland   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916-1919, 1940-1949, 1957-1964 and since 1977.
    Portugal   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916-1921, 1924, 1926-1929, 1931-1932, 1934-1949, 1951-1965 and since 1977.
    Puerto Rico (US)   Central America   -   -   -   Observed DST in 1942-1945.
    Qatar   Asia   -   -   -   Does not use DST
    Romania   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1932-1939 and since 1979.
    Russia   Asia/Europe   -   -   -   Observed DST in 1917-1919 and 1921 (some areas), 1981-2010. Since 2011, used permanent DST. In 2014, left permanent DST and switched to permanent standard time.[7]
    Rwanda   Africa   -   -   -   Does not use DST
    Saba (NL)   Central America   -   -   -   Does not use DST
    Samoa   Oceania   Austral/South   Last Sunday September   First Sunday April   Observed DST since 2011
    Saint Barthélemy (FR)   Central America   -   -   -   Does not use DST
    Saint Kitts and Nevis   Central America   -   -   -   Does not use DST
    Saint Helena, Ascension and Tristan da Cunha (UK)   Africa/South America   -   -   -   Does not use DST
    Saint Lucia   Central America   -   -   -   Does not use DST
    Saint Martin (FR)   Central America   -   -   -   Does not use DST
    Saint Pierre and Miquelon (FR)   North America   Boreal/North   Second Sunday March   First Sunday November   Observed DST since 1987.
    Saint Vincent and the Grenadines   Central America   -   -   -   Does not use DST
    San Marino   Europe   Boreal/North   Last Sunday March   Last Sunday October   Observed DST in 1916-1920, 1940-1948 and since 1966.
    Sao Tome and Príncipe   Africa   -   -   -   Does not use DST
    Saudi Arabia   Asia   -   -   -   Does not use DST
    Senegal   Africa   -   -   -   Does not use DST
    Serbia   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1941-1945 and since 1983.
    Seychelles   Africa   -   -   -   Does not use DST
    Sierra Leone   Africa   -   -   -   Observed DST in 1935-1942 and 1957-1962.
    Singapore   Asia   -   -   -   Observed DST in 1933-1935 by adding 20 minutes to standard time. On January 1, 1936, country changed their time zone to UTC+7:20.[8]
    Sint Eustatius (NL)   Central America   -   -   -   Does not use DST
    Sint Maarten (NL)   Central America   -   -   -   Does not use DST
    Slovakia   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916-1918, 1940-1949 and since 1979.
    Slovenia   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1941-1945 and since 1983.
    Solomon Islands   Oceania   -   -   -   Does not use DST
    Somalia   Africa   -   -   -   Does not use DST
    South Africa   Africa   -   -   -   Observed DST in 1942-1944.
    South Georgia Islands (UK)   Antarctica   -   -   -   Does not use DST
    South Korea   Asia   -   -   -   Observed DST in 1948-1951, 1955-1960 and 1987-1988.
    South Sandwich Islands (UK)   Antarctica   -   -   -   Does not use DST
    South Sudan   Africa   -   -   -   Observed DST in 1970-1985.
    Spain   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1917-1919, 1924, 1926-1929, 1937-1946, 1949 and since 1974. On Canary Islands DST observed since 1980.
    Sri Lanka   Asia   -   -   -   Observed DST in 1942-1945.
    Sudan   Africa   -   -   -   Observed DST in 1970-1985.
    Suriname   South America   -   -   -   Does not use DST
    Swaziland   Africa   -   -   -   Does not use DST
    Sweden   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST 15 May–30 September 1916, and since the first Sunday of April in 1980. Before 1996 it ended on the last Sunday of September.
    Switzerland   Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1941-1942 and since 1980.
    Follows European Union practice, although not a member.

    Syria   Asia   Boreal/North   Last Friday March   Last Friday October   Observed DST in 1920-1923, 1962-1968 and since 1983.
    Tajikistan   Asia   -   -   -   Observed DST in 1981-1991.
    Taiwan   Asia   -   -   -   Observed DST in 1945-1962, 1974, 1975 and 1979.
    Tanzania   Africa   -   -   -   Does not use DST
    Thailand   Asia   -   -   -   Does not use DST
    Tokelau (NZ)   Oceania   -   -   -   Does not use DST
    Togo   Africa   -   -   -   Does not use DST
    Tonga   Oceania   -   -   -   Observed DST from 1999-2002.
    Trinidad and Tobago   Central America   -   -   -   Does not use DST
    Tunisia   Africa   -   -   -   Observed DST in 1939-1945, 1977-1978, 1988-1990 and 2005-2008.
    Turkey   Asia/Europe   Boreal/North   01:00 UTC on last Sunday March   01:00 UTC on last Sunday October   Observed DST in 1916, 1920-1922, 1924-1925, 1940-1942, 1945-1951, 1962, 1964, 1970-1983 and since 1985.
    Follows European Union practice, although not a member.

    Turkmenistan   Asia   -   -   -   Observed DST in 1981-1991.
    Tuvalu   Oceania   -   -   -   Does not use DST
    Turks and Caicos (UK)   Central America   Boreal/North   Second Sunday March   First Sunday November   Observed DST since 1979.
    Uganda   Africa   -   -   -   Does not use DST
    Ukraine   Europe   Boreal/North   Last Sunday March   Last Sunday October   Observed DST in 1941-1943, 1981-1989 and since 1992.
    The Crimean Supreme Council announced that Crimea will switch to Moscow time (which does not observe DST) in March 2014 .[9]

    United Arab Emirates   Asia   -   -   -   Does not use DST
    United Kingdom   Europe   Boreal/North   01:00 GMT on last Sunday March   01:00 GMT on last Sunday October
    Main article: British Summer Time
    Observed DST (British Summer Time (BST)) since 1916.
    Year-round Summer Time (BST) + Double Summer Time (BDST) 1940-1945. Two-stage Double Summer Time (BDST) 1947. Year-round Summer Time (BST) 1968-1971.

    United States   North America   Boreal/North   Second Sunday March   First Sunday November
    Main article: Daylight saving time in the United States
    Arizona (except the Navajo Nation Community) and Hawaii do not use DST.[10]
    United States Virgin Islands (US)   Central America   -   -   -   Does not use DST
    Uruguay   South America   Austral/South   First Sunday October   Second Sunday March   Observed DST in 1923-1926, 1933-1943, 1959-1960, 1965-1970, 1972, 1974-1980, 1987-1993 and since 2004.
    Uzbekistan   Asia   -   -   -   Observed DST in 1981-1991.
    Vanuatu   Oceania   -   -   -   Observed DST in 1983-1993.
    Venezuela   South America   -   -   -   Does not use DST
    Vietnam   Asia   -   -   -   Does not use DST
    Wallis and Futuna (FR)   Oceania   -   -   -   Does not use DST
    Western Sahara   Africa   Boreal/North   Last Sunday March   Last Sunday October   Observed DST since 2008. DST is stopped during Ramadan. Only areas controlled by Morocco uses DST.
    Yemen   Asia   -   -   -   Does not use DST
    Zambia   Africa   -   -   -   Does not use DST
    Zimbabwe   Africa   -   -   -   Does not use DST
         */
    );

    public function __construct($aArgs) {

        //connect to twitter
        $this->oTwitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
        $this->oTwitter->host = "https://api.twitter.com/1.1/";

        //load args
        $this->parseArgs($aArgs);
    }

    private function parseArgs($aArgs) {

        $this->sUsername = (!empty($aArgs['sUsername']) ? $aArgs['sUsername'] : '');
        $this->sLogFile         = (!empty($aArgs['sLogFile'])      ? $aArgs['sLogFile']         : strtolower($this->sUsername) . '.log');

        if ($this->sLogFile == '.log') {
            $this->sLogFile = pathinfo($_SERVER['SCRIPT_FILENAME'], PATHINFO_FILENAME) . '.log';
        }
    }

    public function run() {

        file_put_contents('dstbot.json', json_encode($this->aSettings, JSON_PRETTY_PRINT));
        die('satop');

        //check if auth is ok
        if ($this->getIdentity()) {

            //check for upcoming DST changes and tweet about it
            $this->checkDST();

            //check for menions and reply
            $this->checkMentions();

            $this->halt('Done.');
        }
    }

    private function getIdentity() {

        //DEBUG
        return true;

        echo 'Fetching identity..<br>';

        if (!$this->sUsername) {
            $this->logger(2, 'No username');
            $this->halt('- No username! Set username when calling constructor.');
            return FALSE;
        }

        $oCurrentUser = $this->oTwitter->get('account/verify_credentials', array('include_entities' => FALSE, 'skip_status' => TRUE));

        if (is_object($oCurrentUser) && !empty($oCurrentUser->screen_name)) {
            if ($oCurrentUser->screen_name == $this->sUsername) {
                printf('- Allowed: @%s, continuing.<br><br>', $oCurrentUser->screen_name);
            } else {
                $this->logger(2, sprintf('Authenticated username was unexpected: %s (expected: %s)', $oCurrentUser->screen_name, $this->sUsername));
                $this->halt(sprintf('- Not allowed: @%s (expected: %s), halting.', $oCurrentUser->screen_name, $this->sUsername));
                return FALSE;
            }
        } else {
            $this->logger(2, sprintf('Twitter API call failed: GET account/verify_credentials (%s)', $oCurrentUser->errors[0]->message));
            $this->halt(sprintf('- Call failed, halting. (%s)', $oCurrentUser->errors[0]->message));
            return FALSE;
        }

        return TRUE;
    }

    private function checkDST() {

        //check if any of the countries are switching to DST (summer time) NOW
        if ($aCountries = $this->checkDSTStart(time())) {

            $this->postTweetDSTStart($aCountries, 'now');
        }

        die('stop');

        //check if any of the countries are switching to DST (summer time) in 24 hours
        if ($aCountries = $this->checkDSTStart(time() + 24 * 3600)) {

            $this->postTweetDSTStart($aCountries, '24 hours');
        }

        //check if any of the countries are switching to DST (summer time) in 7 days
        if ($aCountries = $this->checkDSTStart(time() + 7 * 24 * 3600)) {

            $this->postTweetDSTStart($aCountries, '1 week');
        }

        //check if any of the countries are switching from DST (winter time) NOW
        $this->checkDSTEnd(time());

        //check if any of the countries are switching from DST (winter time) in 24 hours
        $this->checkDSTEnd(time() + 24 * 3600);

        //check if any of the countries are switching from DST (winter time) in 7 days
        $this->checkDSTEnd(time() + 7 * 24 * 3600);

        return TRUE;
    }

    //check if DST starts (summer time start) for any of the countries
    private function checkDSTStart($iTimestamp) {

        $aCountriesDSTStart = array();
        foreach ($this->aSettings as $sCountry => $aSetting) {

            if ($aSetting['dst']) {

                //convert 'last sunday of march 2014' to timestamp
                $iDSTStart = strtotime($aSetting['dst']['start'] . ' ' . date('Y'));

                //error margin of 1 minute
                if ($iDSTStart >= $iTimestamp - 60 && $iDSTStart <= $iTimestamp + 60) {

                    //DST will start here
                    $aCountriesDSTStart[] = $sCountry;
                }
            }
        }

        return ($aCountriesDSTStart ? $aCountriesDSTStart : FALSE);
    }

    //check if DST ends (winter time start) for any of the countries
    private function checkDSTEnd($iTimestamp) {
    }

    private function halt($sMessage = '') {
        echo $sMessage . '<br><br>Done!<br><br>';
        return FALSE;
    }

    private function logger($iLevel, $sMessage) {

        $sLogLine = "%s [%s] %s\n";
        $sTimestamp = date('Y-m-d H:i:s');

        switch($iLevel) {
        case 1:
            $sLevel = 'FATAL';
            break;
        case 2:
            $sLevel = 'ERROR';
            break;
        case 3:
            $sLevel = 'WARN';
            break;
        case 4:
        default:
            $sLevel = 'INFO';
            break;
        case 5:
            $sLevel = 'DEBUG';
            break;
        case 6:
            $sLevel = 'TRACE';
            break;
        }

        $iRet = file_put_contents($this->sLogFile, sprintf($sLogLine, $sTimestamp, $sLevel, $sMessage), FILE_APPEND);

        if ($iRet === FALSE) {
            die($sTimestamp . ' [FATAL] Unable to write to logfile!');
        }
    }
}
