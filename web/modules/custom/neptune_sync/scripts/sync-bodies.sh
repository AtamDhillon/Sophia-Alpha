#!/bin/bash

QUERY="query=\
	PREFIX ns1: <file:///home/andnfitz/GovernmentEntities.owl#>\
	PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>\
	SELECT ?object\
	WHERE {?Entity a ns1:CommonwealthBody .\
	?Entity rdfs:label ?object .}"

curl -X POST --data-binary "$QUERY" https://sophia-neptune.cbkhatvpiwzj.ap-southeast-2.neptune.amazonaws.com:8182/sparql > ../../../../sites/default/files/feeds/bodies/bodies.json
