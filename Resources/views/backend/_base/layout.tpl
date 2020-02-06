<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {block name="content/header_tags"}{/block}
</head>
<body role="document">
    <div class="container theme-showcase" role="main">
        {block name="content/main"}{/block}
    </div> <!-- /container -->

    <script type="text/javascript" src="{link file="backend/_resources/js/integrate.min.js"}"></script>
    {block name="content/javascript"}{/block}
</body>
</html>
