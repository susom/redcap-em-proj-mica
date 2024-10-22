<?php
/** @var \Stanford\MICA\MICA $module */
$build_files = $module->generateAssetFiles();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>MICA Chatbot</title>

    <!-- jQuery Slim -->
    <script src="https://code.jquery.com/jquery-3.6.1.slim.min.js"
            integrity="sha256-w8CvhFs7iHNVUtnSP0YKEg00p9Ih13rlL9zGqvLdePA="
            crossorigin="anonymous"></script>

    <!-- Bootstrap CSS -->
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM"
        crossorigin="anonymous"
    />

    <!-- Source Sans Pro Font -->
    <link href="https://fonts.cdnfonts.com/css/source-sans-pro" rel="stylesheet">

    <?php
    echo $module->initializeJavascriptModuleObject();

    $cmds = [
        "window.mica_jsmo_module = " . $module->getJavascriptModuleObjectName()
    ];

    $initial_system_context = $module->initSystemContexts();
    $intro_text = $module->getIntroText();

    $data = !empty($initial_system_context) ? $initial_system_context : null;


    if ($data !== null) {
        $cmds[] = "window.mica_jsmo_module.data = " . json_encode($data);
    }

    if(!is_null($intro_text)){
        $cmds[] = "window.mica_jsmo_module.intro_text = " . json_encode($intro_text);
    }

    if (!empty($init_method)) {
        $cmds[] = "window.mica_jsmo_module.afterRender(mica_jsmo_module." . $init_method . ")";
    }
    ?>

    <!-- Custom JS -->
    <script src="<?= $module->getUrl("assets/jsmo.js", true) ?>"></script>
    <script>
        $(function () {
            <?= implode(";\n", $cmds) ?>;
        });
    </script>

    <?php foreach ($build_files as $file): ?>
        <?= $file ?>
    <?php endforeach; ?>
</head>
<body>
<div id="chatbot_ui_container"></div>
</body>
</html>
