UPDATE oc_category c
JOIN oc_category_description cd ON c.category_id = cd.category_id
SET c.image = 'catalog/categories/mpasi.png'
WHERE cd.name LIKE '%mpasi%' 
  AND cd.language_id = 1;

