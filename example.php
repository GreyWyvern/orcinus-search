<?php

require 'orcinus/search.php';

?><!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="orcinus/css/search.css">
  <title>Orca PHP Search <?php echo $_ODATA['version']; ?></title>
</head>
<body>
  <h1>Orca PHP Search <?php echo $_ODATA['version']; ?></h1>

  <?php $_TEMPLATE->render(); ?> 
</body>
</html>
