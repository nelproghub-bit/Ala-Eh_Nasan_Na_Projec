UPDATE RouteStops s
INNER JOIN routes r ON r.id = s.route_id
SET s.stop_type = 'stop'
WHERE r.route_name = 'Batangas'
  AND (
      (
          s.stop_sequence = 1
          AND s.implementation_note = 'Approximate OpenStreetMap location; confirm the exact stop position locally.'
      )
      OR (
          s.stop_sequence = 6
          AND s.implementation_note = 'Approximate Mabini Circumferential Road map location; confirm the exact stop position locally.'
      )
  );
