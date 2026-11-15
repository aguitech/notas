# Aguitech · Libreta NFC

1. Descomprime el ZIP y sube la carpeta `notas` al directorio público de aguitech.com. Conserva juntos index.php, style.css y app.js.
2. Usa PHP 8.1 o superior con las extensiones session y fileinfo. Deja habilitadas las subidas y configura upload_max_filesize en 10M y post_max_size en 12M o más. Si hay un proxy, su límite también debe permitir ese tamaño.
3. PHP intenta crear `aguitech-notas-data` junto al directorio público. Si el hosting bloquea esa ubicación, crea una carpeta privada fuera del directorio público que PHP pueda escribir y configura su ruta absoluta en la variable de entorno `NOTAS_DATA_DIR`. Usa una carpeta dedicada que no tenga un alias público.
4. Sustituye TU_CODIGO por tu código NFC actual. Abre https://aguitech.com/notas/?code=TU_CODIGO y prueba guardar, editar y eliminar una nota con una imagen. Graba esa misma URL como registro NDEF de tipo URL en la etiqueta NFC.

La libreta es compartida. Cualquiera con el enlace puede leer y modificar las notas y descargar los adjuntos. Usa el enlace con personas que deban tener esos permisos.

Se admite un adjunto por nota. Las imágenes JPG, PNG, WebP y GIF tienen vista previa. También se pueden descargar PDF, TXT, CSV, ZIP, DOCX y XLSX. El servidor valida el tipo de contenido.

Las notas se guardan en notes.csv en UTF-8. Respalda toda la carpeta privada para conservar el CSV y sus adjuntos juntos. Al eliminar una nota también se elimina su adjunto.

## Listas de pendientes

Abre **Pendientes** en cualquier nota y agrega elementos. Toca la casilla para marcar o reabrir un pendiente, y **×** para quitarlo. El indicador muestra cuántos elementos terminaste. Actualiza los archivos de la aplicación y conserva el directorio privado. Las filas originales del CSV se leen automáticamente y reciben la nueva columna al guardar.
