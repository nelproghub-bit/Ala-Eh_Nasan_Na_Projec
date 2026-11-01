SET @batangas_route_id := (
    SELECT id FROM routes WHERE BINARY route_name = BINARY 'Batangas' LIMIT 1
);
SET @seed_batangas_waypoints := IF(
    @batangas_route_id IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM RouteStops WHERE route_id = @batangas_route_id),
    1,
    0
);

INSERT INTO RouteStops (
    route_id, stop_sequence, location_name, area_label, landmark, stop_type, implementation_note
)
SELECT @batangas_route_id, 1, 'Total Gas Station', 'Batangas City', 'DJPMM Access Rd.',
       'stop', 'Approximate OpenStreetMap location; confirm the exact stop position locally.'
WHERE @seed_batangas_waypoints = 1
UNION ALL
SELECT @batangas_route_id, 2, 'Diversion Road', 'Batangas City',
       'In front of Kwatog’s, Brgy. Bolbok', 'boarding_point',
       'Approximate Bolbok map location; confirm the exact stop position locally.'
WHERE @seed_batangas_waypoints = 1
UNION ALL
SELECT @batangas_route_id, 3, 'Grand Terminal', 'Batangas City', 'Batangas City',
       'stop', 'Approximate OpenStreetMap bus-stop location; confirm the exact stop position locally.'
WHERE @seed_batangas_waypoints = 1
UNION ALL
SELECT @batangas_route_id, 4, 'Mabini Tourism Triangle', 'Mabini — POV from Mabini',
       'Left side, F. Castillo Blvd', 'boarding_point',
       'Approximate F. Castillo Boulevard map location; confirm the exact stop position locally.'
WHERE @seed_batangas_waypoints = 1
UNION ALL
SELECT @batangas_route_id, 5, 'In front of 7/11 Mabini', 'Mabini — POV from Mabini',
       'Left side, F. Castillo Blvd', 'boarding_point',
       'Approximate F. Castillo Boulevard map location; confirm the exact stop position locally.'
WHERE @seed_batangas_waypoints = 1
UNION ALL
SELECT @batangas_route_id, 6, 'In front of Iconails Beauty Lounge', 'Mabini — POV from Mabini',
       'Mabini Circumferential Rd', 'stop',
       'Approximate Mabini Circumferential Road map location; confirm the exact stop position locally.'
WHERE @seed_batangas_waypoints = 1;

INSERT INTO route_stop_coordinates (route_stop_id, latitude, longitude)
SELECT s.id, points.latitude, points.longitude
FROM RouteStops s
INNER JOIN (
    SELECT 1 AS stop_sequence, 13.7561539 AS latitude, 121.0516556 AS longitude
    UNION ALL SELECT 2, 13.7724736, 121.0492225
    UNION ALL SELECT 3, 13.7898761, 121.0608519
    UNION ALL SELECT 4, 13.7496838, 120.9405969
    UNION ALL SELECT 5, 13.7489000, 120.9405900
    UNION ALL SELECT 6, 13.7556617, 120.9157575
) AS points ON points.stop_sequence = s.stop_sequence
WHERE s.route_id = @batangas_route_id
  AND @seed_batangas_waypoints = 1;
