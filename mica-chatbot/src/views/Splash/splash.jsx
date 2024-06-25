import React from 'react';
import "./splash.css";
import { Card, Image, Badge, Button, Group, Grid, Title, Text, Space, Center } from '@mantine/core';
import { CCircle } from 'react-bootstrap-icons';
export function Splash({ changeView }) {
    const onClick = () => {
        changeView('home')
    }

    return (
        <div className="container d-flex justify-content-center align-items-center vh-100" style={{maxWidth:'1000px'}}>
            <div className="box">
                <Card shadow="lg" padding="lg" radius="md" withBorder>
                    <Grid>
                        <Grid.Col span={7}>
                            <div>
                                <Text fw={500} c="dimmed">Terms of Usage</Text>
                                <Title order={2}>Welcome</Title>
                                <Space h="sm"/>
                                <Text size="sm">Lorem ipsum dolor sit amet, consectetur adipiscing elit. Mauris in dui
                                    elit. Aenean a nisl ultrices, convallis ipsum quis,
                                    ornare enim. Sed ac scelerisque turpis, et pellentesque nibh. Donec ligula augue,
                                    rutrum non diam ut, euismod fermentum turpis.
                                    Donec nunc ante, facilisis faucibus pellentesque sed, dictum vel nunc. Sed nec enim
                                    nibh. Proin auctor orci a gravida pulvinar.
                                    Sed congue, velit sit amet rutrum ultrices, augue ligula pretium eros, et sagittis
                                    nisl nibh in leo. Praesent dui justo, luctus
                                    a turpis non, euismod tristique nisl</Text>
                                <div>
                                    <Center>
                                        <Button mt="md" radius="md" onClick={onClick} variant="filled"
                                                color="rgba( 140, 21, 21)" style={{width: '120px'}}>
                                            Accept
                                        </Button>
                                    </Center>
                                </div>
                            </div>
                        </Grid.Col>
                        <Grid.Col span={5}>
                            <Center>
                                <div className="Splash"></div>
                            </Center>

                        </Grid.Col>

                    </Grid>
                    <Center>
                        <Text size="xs" c="dimmed">
                            <span style={{verticalAlign:'middle'}}>
                                <CCircle/> Stanford Medicine
                            </span>
                        </Text>
                    </Center>
                </Card>
            </div>
        </div>
    );
}

