<?php

require __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';

session_start();

$template = join('', file('template.html'));

function trimValues(array &$array)
{
    foreach ($array as $key => &$val)
    {
        if (is_array($val))
        {
            return trimValues($val);
        }

        $array[$key] = trim($val);
    }

    return true;
}

// --------------------------------------

/**
 * @return \Simplon\Mysql\Mysql
 */
function getDbInstance()
{
    global $config;

    return new \Simplon\Mysql\Mysql($config['db']['host'], $config['db']['user'], $config['db']['password'], 'johnny');
}

// --------------------------------------

/**
 * @param array $data
 *
 * @return array|bool
 */
function saveSession(array $data)
{
    $tableName = 'sessions';
    $data['created_at'] = time();
    $data['updated_at'] = time();

    $insertId = getDbInstance()->insert($tableName, $data);

    if ($insertId !== false)
    {
        $data['id'] = $insertId;

        return $data;
    }

    return false;
}

// --------------------------------------

/**
 * @param array $data
 *
 * @return array
 */
function updateSession(array $data)
{
    $tableName = 'sessions';
    $data['updated_at'] = time();

    getDbInstance()->update($tableName, ['id' => $data['id']], $data);

    return $data;
}

// --------------------------------------

/**
 * @param $hash
 *
 * @return array|null
 */
function readSessionByHash($hash)
{
    return getDbInstance()->fetchRow('SELECT * FROM sessions WHERE id = :id', ['hash' => $hash]);
}

// --------------------------------------

/**
 * @return array|null
 */
function readSessions()
{
    $sessions = getDbInstance()->fetchRowMany('SELECT * FROM sessions ORDER BY updated_at DESC');
    $allSessionsByHash = [];

    if (!empty($sessions))
    {
        foreach ($sessions as $sess)
        {
            $allSessionsByHash[$sess['hash']] = $sess;
        }
    }

    return $allSessionsByHash;
}

// --------------------------------------

$allSessionsByHash = readSessions();

// ##########################################

if ($_POST)
{
    // read cached post
    if (isset($allSessionsByHash[$_POST['session']]))
    {
        $sessId = $_POST['session'];
        $allSessionsByHash[$_POST['session']] = updateSession($allSessionsByHash[$_POST['session']]);
        $_POST = $allSessionsByHash[$_POST['session']];
        $_POST['session'] = $sessId;
    }

    // cache post
    else
    {
        $postHash = md5($_POST['api'] . $_POST['url'] . $_POST['params']);

        if (!isset($allSessionsByHash[$postHash]))
        {
            $allSessionsByHash[$postHash] = [
                'hash'   => $postHash,
                'url'    => $_POST['url'],
                'api'    => $_POST['api'],
                'params' => $_POST['params'],
            ];

            saveSession($allSessionsByHash[$postHash]);
        }
        else
        {
            updateSession($allSessionsByHash[$postHash]);
        }
    }

    $allSessionsByHash = readSessions();

    // --------------------------------------

    // dont auto post

    if (empty($_POST['session']))
    {
        $url = trim($_POST['url'], '/') . '/';
        $_POST['params'] = preg_replace('/= +/', '=', $_POST['params']);
        $parsedParams = preg_replace("/\n/", "&", $_POST['params']);
        parse_str($parsedParams, $params);
        trimValues($params);

        // fill in tags
        $curlData = [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => $_POST['api'],
            'params'  => $params,
        ];

        try
        {
            $request = Request::jsonRpc($url, $_POST['api'], $params);
        }
        catch (\Exception $e)
        {
            $request = [
                'httpCode' => $e->getCode(),
                'response' => [
                    'error' => [
                        'message' => $e->getMessage(),
                        'file'    => $e->getFile(),
                        'line'    => $e->getLine(),
                        'trace'   => $e->getTrace(),
                    ],
                ]
            ];
        }

        if ($request)
        {
            $response = $request['response'];

            if (isset($response['error']) && !isset($response['id']))
            {
                $response = [
                    'jsonrpc' => '2.0',
                    'error'   => $response['error'],
                    'id'      => 1,
                ];
            }

            $httpCode = $request['httpCode'];

            if (preg_match('/^[45]/', $httpCode))
            {
                $httpCode = '<span style="font-size:22px;color:#c00">' . $httpCode . '</span>';
            }

            else
            {
                $httpCode = '<span style="font-size:22px;color:#090">' . $httpCode . '</span>';
            }

            $template = str_replace('{{httpCode}}', $httpCode, $template);
            $template = str_replace('{{response}}', json_encode($response), $template);
        }

        $template = str_replace('{{curlRequest}}', 'CURL -v -H \'Content-type: application/json\' \'' . $url . '\' -d \'' . json_encode($curlData) . '\'', $template);
    }

    $template = str_replace('{{url}}', $_POST['url'], $template);
    $template = str_replace('{{api}}', $_POST['api'], $template);
    $template = str_replace('{{params}}', $_POST['params'], $template);
}

// ##########################################

// build sessions

$options = ['<option value="">No sessions available...</option>'];

if (!empty($allSessionsByHash))
{
    $options = [];

    foreach ($allSessionsByHash as $sess)
    {
        $options[] = '<option value="' . $sess['hash'] . '">' . $sess['updated_at'] . ' --> ' . $sess['url'] . ' --> ' . $sess['api'] . '</option>';
    }

    array_unshift($options, '<option value="">Choose session...</option>');
}

$template = str_replace('{{sessions}}', join('', $options), $template);

// ##########################################

// remove left tags
$template = str_replace('{{response}}', '{}', $template);
$template = preg_replace('/\{\{.*\}\}/', '', $template);

echo $template;