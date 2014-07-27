<?php

require __DIR__ . '/vendor/autoload.php';

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
    return new \Simplon\Mysql\Mysql('127.0.0.1', 'rootuser', 'rootuser', 'johnny');
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
        $url = trim($_POST['url'], '/');
        $parsedParams = preg_replace("/\n/", "&", $_POST['params']);
        parse_str($parsedParams, $params);
        trimValues($params);

        $response = Request::jsonRpc($url, $_POST['api'], $params);

        if ($response)
        {
            if (isset($response['error']))
            {
                $response = $response['error'];
            }

            $template = str_replace('{{response}}', json_encode($response), $template);
        }
    }

    // fill in tags
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