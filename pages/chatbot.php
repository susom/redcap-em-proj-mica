<?php
/** @var \Stanford\MICA\MICA $module */
$build_files = $module->generateAssetFiles();
?>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>MICA Chatbot</title>
    <script src="https://code.jquery.com/jquery-3.2.1.slim.min.js" integrity="sha384-KJ3o2DKtIkvYIK3UENzmM7KCkRr/rE9/Qpg6aAZGJwFDMVNA/GpGFF93hXpG5KkN" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.1.slim.min.js" integrity="sha256-w8CvhFs7iHNVUtnSP0YKEg00p9Ih13rlL9zGqvLdePA=" crossorigin="anonymous"></script>

    <?php
    require APP_PATH_DOCROOT . "ExternalModules/manager/templates/hooks/every_page_top.php";

    echo $module->initializeJavascriptModuleObject();
    $cmds = [
        "window.mica_jsmo_module = " . $module->getJavascriptModuleObjectName()
    ];
    $initial_system_context = $module->appendSystemContext(array(), $module->system_context_persona);
    $initial_system_context = $module->appendSystemContext($initial_system_context, $module->system_context_steps);
    $initial_system_context = $module->appendSystemContext($initial_system_context, $module->system_context_rules);

    $data = !empty($initial_system_context) ? $initial_system_context : null;
    if (!empty($data)) {
        $cmds[] = "window.mica_jsmo_module.data = " . json_encode($data);
    }
    if (!empty($init_method)) {
        $cmds[] = "window.mica_jsmo_module.afterRender(mica_jsmo_module." . $init_method . ")";
    }
    ?>
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM"
        crossorigin="anonymous"
    />
    <link href="https://fonts.cdnfonts.com/css/source-sans-pro" rel="stylesheet">

    <script src="<?= $module->getUrl("assets/jsmo.js", true) ?>"></script>
    <script>
        $(function () { <?php echo implode(";\n", $cmds) ?> })
    </script>
    <?php
    foreach ($build_files as $file)
        echo $file;
    ?>
</head>
<body>
<div id="chatbot_ui_container"></div>
</body>
</html>

