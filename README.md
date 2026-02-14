# IOT PROJECT

```
touch .gitignore && mkdir includes && mkdir uploads && touch .htaccess
```

Watch CSS:
```
npx tailwindcss -i ./assets/css/input.css -o ./assets/css/output.css --watch
```

Deployment:

Minify CSS:
```
npx tailwindcss -i ./assets/css/input.css -o ./assets/css/output.css --minify
```

## Uploading files:

Give permission to upload folder: 
```
mkdir uploads
chmod 0755 uploads
sudo chown -R daemon:daemon uploads
```

Push to production:
```
zip -r ../iot_production.zip . -x "uploads/*" -x "*.DS_Store" -x "README.md" -x ".gitignore" -x ".git/*"
```