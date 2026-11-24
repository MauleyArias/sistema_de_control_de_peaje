<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Peaje · Swagger</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui.min.css">
    <style>body { margin:0; background:#fff; }</style>
</head>
<body>
<div id="swagger"></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui-bundle.min.js"></script>
<script>
    SwaggerUIBundle({ url: @json(route('api.openapi')), dom_id: '#swagger', persistAuthorization: true });
</script>
</body>
</html>
