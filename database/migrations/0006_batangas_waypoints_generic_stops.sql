UPDATE RouteStops s
INNER JOIN routes r ON r.id = s.route_id
SET s.stop_type = 'stop'
WHERE r.route_name = 'Batangas'
  AND s.implementation_note IN (
      'Approximate OpenStreetMap location; confirm the exact stop position locally.',
      'Approximate Bolbok map location; confirm the exact stop position locally.',
      'Approximate OpenStreetMap bus-stop location; confirm the exact stop position locally.',
      'Approximate F. Castillo Boulevard map location; confirm the exact stop position locally.',
      'Approximate Mabini Circumferential Road map location; confirm the exact stop position locally.'
  );
